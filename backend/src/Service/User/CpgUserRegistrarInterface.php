<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Entity\CpgUser;
use App\Exception\UsernameAlreadyUsedException;

interface CpgUserRegistrarInterface
{
    /**
     * @throws UsernameAlreadyUsedException si le nom d'utilisateur est déjà utilisé
     */
    public function register(string $username, string $plainPassword): CpgUser;
}
