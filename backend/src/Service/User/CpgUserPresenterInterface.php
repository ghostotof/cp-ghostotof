<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Entity\CpgUser;

interface CpgUserPresenterInterface
{
    /**
     * @return array{username: string, roles: list<string>}
     */
    public function present(CpgUser $user): array;
}
