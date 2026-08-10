<?php

declare(strict_types=1);

namespace App\Security\User\Application;

use App\Security\User\Domain\Entity\CpgUser;

interface CpgUserPresenterInterface
{
    /**
     * @return array{username: string, roles: list<string>}
     */
    public function present(CpgUser $user): array;
}
