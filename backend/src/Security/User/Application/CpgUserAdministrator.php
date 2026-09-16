<?php

declare(strict_types=1);

namespace App\Security\User\Application;

use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\CannotDeleteLastSuperAdminException;
use App\Security\User\Domain\Exception\CannotDeleteOwnAccountException;
use App\Security\User\Domain\Exception\CpgUserNotFoundException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

final readonly class CpgUserAdministrator implements CpgUserAdministratorInterface
{
    public function __construct(
        private CpgUserRepositoryInterface $cpgUserRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private SecurityAuditLoggerInterface $auditLogger,
    ) {
    }

    public function delete(Uuid $id, CpgUser $actingUser): void
    {
        // equals() et non === : deux Uuid identiques restent deux objets
        // distincts, qu'une comparaison d'identité déclarerait différents.
        if ($id->equals($actingUser->getId())) {
            throw CannotDeleteOwnAccountException::forUsername($actingUser->getUsername());
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
            throw CannotDeleteLastSuperAdminException::forUsername($user->getUsername());
        }

        $this->cpgUserRepository->remove($user);
        // Journal de sécurité (D5) : l'entité est encore en main après le
        // remove(), c'est elle qui nomme le compte supprimé.
        $this->auditLogger->userDeleted($user);
    }

    public function changePassword(Uuid $id, string $newPlainPassword): void
    {
        $user = $this->cpgUserRepository->findOneById($id);

        if (null === $user) {
            throw CpgUserNotFoundException::forId($id);
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $newPlainPassword));
        $this->cpgUserRepository->save($user);
        // Le compte, jamais le mot de passe (D5).
        $this->auditLogger->passwordChanged($user);
    }
}
