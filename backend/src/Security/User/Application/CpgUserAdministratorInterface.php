<?php

declare(strict_types=1);

namespace App\Security\User\Application;

use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\CannotDeleteOwnAccountException;
use App\Security\User\Domain\Exception\CpgUserNotFoundException;

interface CpgUserAdministratorInterface
{
    /**
     * @throws CannotDeleteOwnAccountException si $id correspond à $actingUser
     * @throws CpgUserNotFoundException si l'id est inconnu
     */
    public function delete(int $id, CpgUser $actingUser): void;

    /**
     * @throws CpgUserNotFoundException si l'id est inconnu
     */
    public function changePassword(int $id, string $newPlainPassword): void;
}
