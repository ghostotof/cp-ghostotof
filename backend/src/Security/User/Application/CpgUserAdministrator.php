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
        private AccountAdministrationLockInterface $administrationLock,
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
            if (\in_array(CpgUser::ROLE_SUPER, $target->getRoles(), true)
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
