<?php

declare(strict_types=1);

namespace App\Security\User\Application;

use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\CannotDeleteLastSuperAdminException;
use App\Security\User\Domain\Exception\CannotDeleteOwnAccountException;
use App\Security\User\Domain\Exception\CpgUserNotFoundException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Security\User\Domain\Repository\PasswordSetupTokenRepositoryInterface;
use App\Shared\Application\Security\AccountAccessWithdrawal;
use App\Shared\Application\Security\AccountMcpConnectionsRevokerInterface;
use App\Shared\Application\Security\RevokedAccountMcpConnections;
use Psr\Clock\ClockInterface;
use SensitiveParameter;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

final readonly class CpgUserAdministrator implements CpgUserAdministratorInterface
{
    public function __construct(
        private CpgUserRepositoryInterface $cpgUserRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private SecurityAuditLoggerInterface $auditLogger,
        private PasswordSetupTokenRepositoryInterface $passwordSetupTokenRepository,
        private AccountLockInterface $accountLock,
        private AccountAdministrationLockInterface $administrationLock,
        private AccountMcpConnectionsRevokerInterface $mcpConnections,
        private ClockInterface $clock,
        private DeadlockRetryInterface $deadlockRetry,
    ) {
    }

    public function delete(Uuid $id, CpgUser $actingUser): void
    {
        // equals() et non === : deux Uuid identiques restent deux objets
        // distincts, qu'une comparaison d'identité déclarerait différents.
        if ($id->equals($actingUser->getId())) {
            throw CannotDeleteOwnAccountException::forActingAccount();
        }

        $user = $this->cpgUserRepository->findOneById($id);

        if (null === $user) {
            throw CpgUserNotFoundException::forId($id);
        }

        // Sous le verrou d'administration (issues #551, #552) : la cible relue
        // et verrouillée — disparue entre-temps, c'est un 404, pas un 204 et
        // une ligne d'audit pour rien —, l'acteur relu, la garde comptée en
        // base, puis l'écriture, validée au retour du verrou.
        $this->administrationLock->whileAdministering($user, $actingUser, function (CpgUser $target): void {
            // Garde anti-lockout (B9) : ne jamais laisser supprimer le dernier
            // ROLE_SUPER capable de se connecter. La cible est exclue du
            // décompte : c'est elle qui part.
            if ($target->isSuperAdmin()
                && 0 === $this->cpgUserRepository->countOtherSuperAdminsAbleToSignIn($target)
            ) {
                throw CannotDeleteLastSuperAdminException::lastOne();
            }

            $this->cpgUserRepository->remove($target);
        });
        // Journal de sécurité (D5), après le COMMIT : l'entité est encore en
        // main après le remove(), c'est elle qui nomme le compte supprimé.
        $this->auditLogger->userDeleted($user);
    }

    public function changePassword(Uuid $id, #[SensitiveParameter] string $newPlainPassword): void
    {
        $user = $this->cpgUserRepository->findOneById($id);

        if (null === $user) {
            throw CpgUserNotFoundException::forId($id);
        }

        // Le hachage hors verrou (il est lent), l'écriture sous le verrou du
        // compte, celui de l'invitation et de l'activation (issue #460) : une
        // activation concurrente ne se mêle pas à ce changement.
        //
        // **Ordre des verrous** : la ligne du compte (`FOR UPDATE`, prise par
        // le verrou), puis ses connexions MCP par identifiant — aucun chemin ne
        // prend une connexion puis le compte (voir `replacePasswordOf()`).
        // **Un cycle avec le premier échange du code d'un nouvel accord reste
        // pourtant possible** (#650, F1 : PostgreSQL annule l'une des deux
        // transactions, `40P01`, les deux restent atomiques —
        // `AccountWideRevocationDeadlockTest`). **Annulée, cette transaction est
        // relancée une fois** (S3, CR4) : sans cela, l'administrateur qui change
        // un mot de passe compromis recevait un 500, le mot de passe inchangé et
        // les connexions vivantes.
        //
        // **Toute la transaction verrouillée est rejouée**, sur le compte
        // **relu** au second essai : la transaction annulée avait écrit, donc
        // fermé l'EntityManager (la relance le remet à neuf) et détaché
        // l'instance d'avant. Le hachage n'est pas recalculé ; le journal
        // ci-dessous suit le seul COMMIT qui a abouti, il s'écrit une fois.
        $hashedPassword = $this->passwordHasher->hashPassword($user, $newPlainPassword);
        $revoked = $this->deadlockRetry->retryOnce(function (bool $isRetry) use (&$user, $id, $hashedPassword): RevokedAccountMcpConnections {
            if ($isRetry) {
                // Disparu entre les deux essais : 404, comme au premier.
                $user = $this->cpgUserRepository->findOneById($id) ?? throw CpgUserNotFoundException::forId($id);
            }

            return $this->accountLock->whileLocked($user, fn (CpgUser $locked): RevokedAccountMcpConnections => $this->replacePasswordOf($locked, $hashedPassword));
        });
        // Le compte, jamais le mot de passe (D5), puis une ligne par connexion
        // coupée : après le COMMIT, un acte journalisé est un acte validé.
        $this->auditLogger->passwordChanged($user);
        $this->mcpConnections->auditRevoked($revoked);
    }

    /**
     * Le corps de la transaction verrouillée, sur le compte relu sous le verrou.
     *
     * Ordre des verrous : le compte (`FOR UPDATE`, pris par le verrou), puis
     * ses connexions par identifiant — aucun chemin ne prend une connexion puis
     * le compte (le point de jeton lit le compte sans verrou, le consentement ne
     * prend qu'un `FOR KEY SHARE` du compte avant d'insérer, la suppression en
     * cascade suit le même sens). Le port des connexions est dans `Shared` plutôt
     * qu'une classe de `Mcp` : `Security` ne dépend d'aucune classe de `Mcp`.
     */
    private function replacePasswordOf(CpgUser $locked, string $hashedPassword): RevokedAccountMcpConnections
    {
        // Le mot de passe, **sa date et la version des identifiants** (F2 de
        // la relecture de #650, S1 de la seconde) : tout cookie de session qui
        // porte l'ancienne version est refusé par l'API entière
        // (`CpgUser::claimsAStaleCredentialsVersion()`), ce que la seule
        // coupure des connexions existantes ne couvrait pas. Écrit sur le
        // compte relu sous le verrou — l'incrément en mémoire n'est sûr que
        // là —, dans la même transaction que le mot de passe : jamais l'un
        // sans l'autre.
        $locked->replacePassword($hashedPassword, $this->clock->now());
        $this->cpgUserRepository->save($locked);
        // Compte encore en attente d'activation : le mot de passe posé ici
        // fait foi, le lien d'invitation en circulation meurt (décision du
        // 2026-10-11) — c'est le cas utile quand le lien est douteux
        // (e-mail transféré, boîte compromise). Une nouvelle invitation
        // reste possible.
        if ($locked->isPendingActivation()) {
            $this->passwordSetupTokenRepository->deleteUnusedForUser($locked);
        }

        // La révocation des connexions MCP du compte (#650) a lieu **dans** la
        // transaction : le nouveau mot de passe et la coupure des connexions
        // valident ensemble ou pas du tout — l'une qui échoue annule l'autre.
        // Un événement après le COMMIT n'aurait pas cette atomicité (un échec
        // laisserait un mot de passe changé et des connexions vivantes).
        return $this->mcpConnections->revokeAllOf($locked, AccountAccessWithdrawal::PasswordChanged);
    }
}
