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

    public function findOneById(int $id): ?CpgUser;

    /**
     * @return list<CpgUser>
     */
    public function findAll(): array;

    public function save(CpgUser $user): void;

    public function remove(CpgUser $user): void;
}
