<?php

declare(strict_types=1);

namespace App\Security\User\Application;

use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\UsernameAlreadyUsedException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class CpgUserRegistrar implements CpgUserRegistrarInterface
{
    public function __construct(
        private CpgUserRepositoryInterface $cpgUserRepository,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function register(string $username, string $plainPassword): CpgUser
    {
        if (null !== $this->cpgUserRepository->findOneByUsername($username)) {
            throw UsernameAlreadyUsedException::forUsername($username);
        }

        $user = new CpgUser($username, '');
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));

        $this->cpgUserRepository->save($user);

        return $user;
    }
}
