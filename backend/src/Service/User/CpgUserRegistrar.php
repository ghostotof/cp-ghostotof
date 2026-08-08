<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Entity\CpgUser;
use App\Exception\UsernameAlreadyUsedException;
use App\Repository\CpgUserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class CpgUserRegistrar implements CpgUserRegistrarInterface
{
    public function __construct(
        private CpgUserRepository $cpgUserRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function register(string $username, string $plainPassword): CpgUser
    {
        if (null !== $this->cpgUserRepository->findOneByUsername($username)) {
            throw UsernameAlreadyUsedException::forUsername($username);
        }

        $user = new CpgUser($username, '');
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
