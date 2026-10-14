<?php

declare(strict_types=1);

namespace App\Security\User\Application;

use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\CannotDemoteLastSuperAdminException;
use App\Security\User\Domain\Exception\CannotModifyOwnRolesException;
use App\Security\User\Domain\Exception\CpgUserNotFoundException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use Symfony\Component\Uid\Uuid;

final readonly class CpgUserRoleAdministrator implements CpgUserRoleAdministratorInterface
{
    public function __construct(
        private CpgUserRepositoryInterface $cpgUserRepository,
        private SecurityAuditLoggerInterface $auditLogger,
        private AccountAdministrationLockInterface $administrationLock,
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
        $changed = $this->administrationLock->whileAdministering($user, $actingUser, function (CpgUser $target) use ($grant): bool {
            $isSuper = $target->isSuperAdmin();

            if ($grant === $isSuper) {
                // Déjà dans l'état demandé : rien à faire (et la garde du dernier
                // super-admin ne doit pas être consultée pour une cible non-super).
                return false;
            }

            // Garde anti-lockout (pendant de CpgUserAdministrator::delete) :
            // jamais le dernier ROLE_SUPER capable de se connecter, la cible
            // exclue du décompte puisque c'est elle qui perd le rôle.
            if (!$grant && 0 === $this->cpgUserRepository->countOtherSuperAdminsAbleToSignIn($target)) {
                throw CannotDemoteLastSuperAdminException::lastOne();
            }

            $target->setRoles($grant ? [CpgUser::ROLE_SUPER] : $this->rolesAfterDemotion($target));
            $this->cpgUserRepository->save($target);

            return true;
        });

        // Journal de sécurité (D5), après le COMMIT : seulement sur un
        // changement effectif — l'état déjà atteint est sorti sans rien écrire.
        if ($changed) {
            $this->auditLogger->roleChanged($user, $grant);
        }
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
