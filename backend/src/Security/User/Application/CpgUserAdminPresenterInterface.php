<?php

declare(strict_types=1);

namespace App\Security\User\Application;

use App\Security\User\Domain\Entity\CpgUser;

/**
 * Distinct de CpgUserPresenterInterface (utilisé par GET /api/me, qui ne
 * concerne que l'utilisateur courant et n'a jamais besoin de son propre id) :
 * celui-ci expose l'id, nécessaire aux actions backoffice (delete,
 * changePassword) qui ciblent un utilisateur par id.
 */
interface CpgUserAdminPresenterInterface
{
    /**
     * @return array{id: int, username: string, roles: list<string>}
     */
    public function present(CpgUser $user): array;
}
