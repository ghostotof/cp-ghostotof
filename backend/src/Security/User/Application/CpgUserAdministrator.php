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

        // Garde anti-lockout (B9) : ne jamais laisser supprimer le dernier
        // ROLE_SUPER. `<= 1` plutôt que `=== 1` par prudence (jamais 0 ici,
        // puisque $user en fait partie).
        if (\in_array(CpgUser::ROLE_SUPER, $user->getRoles(), true)
            && $this->cpgUserRepository->countByRole(CpgUser::ROLE_SUPER) <= 1
        ) {
            throw CannotDeleteLastSuperAdminException::lastOne();
        }

        $this->cpgUserRepository->remove($user);
        // Journal de sécurité (D5) : l'entité est encore en main après le
        // remove(), c'est elle qui nomme le compte supprimé.
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
        $hashedPassword = $this->passwordHasher->hashPassword($user, $newPlainPassword);
        $this->accountLock->whileLocked($user, function (CpgUser $locked) use ($hashedPassword): void {
            $locked->setPassword($hashedPassword);
            $this->cpgUserRepository->save($locked);
            // Compte encore en attente d'activation : le mot de passe posé ici
            // fait foi, le lien d'invitation en circulation meurt (décision du
            // 2026-10-11) — c'est le cas utile quand le lien est douteux
            // (e-mail transféré, boîte compromise). Une nouvelle invitation
            // reste possible.
            if ($locked->isPendingActivation()) {
                $this->passwordSetupTokenRepository->deleteUnusedForUser($locked);
            }
        });
        // Le compte, jamais le mot de passe (D5).
        $this->auditLogger->passwordChanged($user);
    }
}
