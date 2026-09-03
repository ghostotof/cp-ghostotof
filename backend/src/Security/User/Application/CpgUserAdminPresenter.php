<?php

declare(strict_types=1);

namespace App\Security\User\Application;

use App\Security\User\Domain\Entity\CpgUser;

final class CpgUserAdminPresenter implements CpgUserAdminPresenterInterface
{
    public function present(CpgUser $user): array
    {
        return [
            'id' => (int) $user->getId(),
            'username' => $user->getUsername(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
            'status' => $user->isPendingActivation() ? 'pending' : 'active',
        ];
    }
}
