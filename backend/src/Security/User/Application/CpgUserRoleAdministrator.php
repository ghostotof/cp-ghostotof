<?php

declare(strict_types=1);

namespace App\Security\User\Application;

use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\CannotDemoteLastSuperAdminException;
use App\Security\User\Domain\Exception\CannotModifyOwnRolesException;
use App\Security\User\Domain\Exception\CpgUserNotFoundException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Shared\Application\Security\AccountAccessWithdrawal;
use App\Shared\Application\Security\AccountMcpConnectionsRevokerInterface;
use App\Shared\Application\Security\RevokedAccountMcpConnections;
use Symfony\Component\Uid\Uuid;

final readonly class CpgUserRoleAdministrator implements CpgUserRoleAdministratorInterface
{
    public function __construct(
        private CpgUserRepositoryInterface $cpgUserRepository,
        private SecurityAuditLoggerInterface $auditLogger,
        private AccountAdministrationLockInterface $administrationLock,
        private AccountMcpConnectionsRevokerInterface $mcpConnections,
        private DeadlockRetryInterface $deadlockRetry,
    ) {
    }

    public function setSuperAdmin(Uuid $id, bool $grant, CpgUser $actingUser): void
    {
        // equals() et non === : cf. CpgUserAdministrator::delete().
        if ($id->equals($actingUser->getId())) {
            throw CannotModifyOwnRolesException::forActingAccount();
        }

        $user = $this->cpgUserRepository->findOneById($id);

        if (null === $user) {
            throw CpgUserNotFoundException::forId($id);
        }

        // Sous le verrou d'administration (issues #551, #552) : la cible relue
        // et verrouillée — disparue entre-temps, c'est un 404, pas un 204 et
        // un `role-changed` pour rien —, l'acteur relu, l'état « déjà
        // atteint » et la garde lus sur le compte relu, puis l'écriture,
        // validée au retour du verrou.
        //
        // La révocation des connexions MCP d'un compte qui perd `ROLE_TRUSTED`
        // (#650) a lieu **dans** la fermeture : le rôle et la coupure valident
        // ensemble ou pas du tout — la révocation qui échoue annule le rôle.
        // Un événement après le COMMIT n'aurait pas cette atomicité (un échec
        // laisserait un compte rétrogradé et ses connexions vivantes).
        // **Ordre des verrous** : l'advisory lock d'administration, l'acteur,
        // la cible `FOR UPDATE`, puis ses connexions par identifiant — le sens
        // de tous les autres chemins (cf. CpgUserAdministrator::changePassword()).
        // La fermeture rend un RoleChangeOutcome : le changement effectif et,
        // le cas échéant, les connexions révoquées.
        //
        // **Relancée une fois sur un interblocage** (S3, CR4 ; le cycle F1 avec
        // le premier échange d'un code, `AccountWideRevocationDeadlockTest`) :
        // sans cela, la rétrogradation d'un compte compromis rendait un 500, le
        // rôle inchangé et les connexions vivantes. Toute la transaction est
        // rejouée, sans rien relire ici : le verrou d'administration relit la
        // cible **et** l'acteur par leur identifiant sur l'EntityManager remis à
        // neuf — les instances de ce service (`$user`, `$actingUser`) ne servent
        // qu'à lui donner ces identifiants et au journal ci-dessous.
        $outcome = $this->deadlockRetry->retryOnce(fn (): RoleChangeOutcome => $this->administrationLock->whileAdministering(
            $user,
            $actingUser,
            fn (CpgUser $target): RoleChangeOutcome => $this->writeRole($target, $grant),
        ));

        // Journal de sécurité (D5), après le COMMIT : seulement sur un
        // changement effectif — l'état déjà atteint est sorti sans rien écrire.
        // Puis une ligne par connexion coupée (#650), à la suite de `role-changed`.
        if ($outcome->changed) {
            $this->auditLogger->roleChanged($user, $grant);
        }

        if ($outcome->revoked instanceof RevokedAccountMcpConnections) {
            $this->mcpConnections->auditRevoked($outcome->revoked);
        }
    }

    /**
     * Le corps de la fermeture, sur la cible relue et verrouillée : l'état
     * « déjà atteint », la garde anti-lockout, l'écriture du rôle et, si le
     * palier de confiance est perdu, la révocation des connexions du compte.
     */
    private function writeRole(CpgUser $target, bool $grant): RoleChangeOutcome
    {
        if ($grant === $target->isSuperAdmin()) {
            // Déjà dans l'état demandé : rien à faire (et la garde du dernier
            // super-admin ne doit pas être consultée pour une cible non-super).
            return RoleChangeOutcome::unchanged();
        }

        // Garde anti-lockout (pendant de CpgUserAdministrator::delete) :
        // jamais le dernier ROLE_SUPER capable de se connecter, la cible
        // exclue du décompte puisque c'est elle qui perd le rôle.
        if (!$grant && 0 === $this->cpgUserRepository->countOtherSuperAdminsAbleToSignIn($target)) {
            throw CannotDemoteLastSuperAdminException::lastOne();
        }

        $newRoles = $grant ? [CpgUser::ROLE_SUPER] : $this->rolesAfterDemotion($target);
        $target->setRoles($newRoles);
        $this->cpgUserRepository->save($target);

        // Le palier est perdu quand une **rétrogradation** écrit une liste
        // sans `ROLE_TRUSTED` : la cible était `ROLE_SUPER` (l'exit
        // idempotent est passé), donc de confiance par la hiérarchie, et
        // ne l'est plus. On lit la liste **écrite** (`$newRoles`), jamais
        // `getRoles()` qui y ajouterait `ROLE_USER` (règle de #560), ni
        // l'état d'avant : un compte avec e-mail garde `ROLE_TRUSTED` et ses
        // connexions. Une promotion n'ôte rien (`ROLE_SUPER` englobe
        // `ROLE_TRUSTED`).
        $losesTrustedTier = !$grant && !\in_array(CpgUser::ROLE_TRUSTED, $newRoles, true);

        return RoleChangeOutcome::changed(
            $losesTrustedTier ? $this->mcpConnections->revokeAllOf($target, AccountAccessWithdrawal::TrustedTierWithdrawn) : null,
        );
    }

    /**
     * ADR 0003 D1 (issue #78, pt 3). ROLE_SUPER englobe ROLE_TRUSTED via la
     * role_hierarchy, mais ROLE_TRUSTED ne s'accorde que nominativement : par
     * l'invitation, qui lie l'octroi à une adresse e-mail, donc à une personne.
     * Un compte sur invitation retrouve donc son palier réel à la rétrogradation — il
     * n'y a pas de perte de confiance implicite dans un acte administratif.
     * Un compte CLI, lui, n'a pas d'e-mail : personne ne l'a jamais accordé, et
     * la CLI refuse justement `--role ROLE_TRUSTED` (Task 12). Le promouvoir
     * puis le rétrograder ne doit pas être une voie détournée vers le CV : il
     * retombe au palier de base (ROLE_USER, ajouté par getRoles()).
     *
     * @return list<string>
     */
    private function rolesAfterDemotion(CpgUser $user): array
    {
        return null !== $user->getEmail() ? [CpgUser::ROLE_TRUSTED] : [];
    }
}
