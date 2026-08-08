<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\User;

use App\Entity\CpgUser;
use App\Exception\UsernameAlreadyUsedException;
use App\Repository\CpgUserRepository;
use App\Service\User\CpgUserRegistrar;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CpgUserRegistrarTest extends TestCase
{
    public function testRegisterHashesPasswordAndPersistsUser(): void
    {
        $repository = $this->createMock(CpgUserRepository::class);
        $repository->expects(self::once())
            ->method('findOneByUsername')
            ->with('jane')
            ->willReturn(null);

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects(self::once())
            ->method('hashPassword')
            ->with(self::isInstanceOf(CpgUser::class), 'plain-password')
            ->willReturn('hashed-password');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(CpgUser::class));
        $entityManager->expects(self::once())->method('flush');

        $registrar = new CpgUserRegistrar($repository, $hasher, $entityManager);

        $user = $registrar->register('jane', 'plain-password');

        self::assertSame('jane', $user->getUsername());
        self::assertSame('hashed-password', $user->getPassword());
    }

    public function testRegisterThrowsWhenUsernameAlreadyUsed(): void
    {
        $existingUser = new CpgUser('jane', 'hashed-password');

        $repository = $this->createMock(CpgUserRepository::class);
        $repository->expects(self::once())->method('findOneByUsername')->with('jane')->willReturn($existingUser);

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects(self::never())->method('hashPassword');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');

        $registrar = new CpgUserRegistrar($repository, $hasher, $entityManager);

        $this->expectException(UsernameAlreadyUsedException::class);

        $registrar->register('jane', 'plain-password');
    }
}
