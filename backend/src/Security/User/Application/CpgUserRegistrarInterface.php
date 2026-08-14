<?php

declare(strict_types=1);

namespace App\Security\User\Application;

use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\UsernameAlreadyUsedException;

interface CpgUserRegistrarInterface
{
    /**
     * @param list<string> $roles rôles additionnels (ex. CpgUser::ROLE_SUPER) ; ROLE_USER est toujours implicite
     *
     * @throws UsernameAlreadyUsedException si le nom d'utilisateur est déjà utilisé
     */
    public function register(string $username, string $plainPassword, array $roles = []): CpgUser;
}
