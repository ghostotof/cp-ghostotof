<?php

declare(strict_types=1);

namespace App\Security\User\Application;

use App\Security\User\Domain\Entity\CpgUser;

final class CpgUserPresenter implements CpgUserPresenterInterface
{
    public function present(CpgUser $user): array
    {
        return [
            'username' => $user->getUsername(),
            'roles' => $user->getRoles(),
        ];
    }
}
