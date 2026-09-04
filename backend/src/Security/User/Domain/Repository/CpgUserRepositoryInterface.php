<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Repository;

use App\Security\User\Domain\Entity\CpgUser;

/**
 * Abstraction (DIP) dont dépend la couche Application (CpgUserRegistrar) :
 * elle ne connaît jamais Doctrine directement. L'implémentation concrète vit
 * dans Infrastructure\Doctrine\CpgUserRepository.
 */
interface CpgUserRepositoryInterface
{
    public function findOneByUsername(string $username): ?CpgUser;

    public function findOneByEmail(string $email): ?CpgUser;

    public function findOneById(int $id): ?CpgUser;

    /**
     * @return list<CpgUser>
     */
    public function findAll(): array;

    /**
     * Nombre d'utilisateurs possédant le rôle donné (rôles implicites inclus,
     * cf. CpgUser::getRoles()). Utilisé par la garde anti-lockout du dernier
     * ROLE_SUPER (cf. CpgUserAdministrator::delete).
     */
    public function countByRole(string $role): int;

    public function save(CpgUser $user): void;

    public function remove(CpgUser $user): void;
}
