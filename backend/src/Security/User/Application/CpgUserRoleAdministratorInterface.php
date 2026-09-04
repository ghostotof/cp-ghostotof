<?php

declare(strict_types=1);

namespace App\Security\User\Application;

use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\CannotDemoteLastSuperAdminException;
use App\Security\User\Domain\Exception\CannotModifyOwnRolesException;
use App\Security\User\Domain\Exception\CpgUserNotFoundException;

/**
 * Gestion du rôle ROLE_SUPER d'un compte depuis le backoffice. Séparé de
 * CpgUserAdministrator (delete / changePassword) : l'attribution de rôle a ses
 * propres gardes (pas soi-même, pas le dernier super-admin).
 */
interface CpgUserRoleAdministratorInterface
{
    /**
     * Accorde (`$grant = true`) ou retire (`$grant = false`) le rôle
     * ROLE_SUPER. Idempotent : si le compte est déjà dans l'état demandé,
     * ne fait rien.
     *
     * @throws CannotModifyOwnRolesException si $id est le compte de $actingUser
     * @throws CpgUserNotFoundException si l'id est inconnu
     * @throws CannotDemoteLastSuperAdminException si le retrait viderait le dernier ROLE_SUPER
     */
    public function setSuperAdmin(int $id, bool $grant, CpgUser $actingUser): void;
}
