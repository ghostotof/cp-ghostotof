<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Application;

use App\Security\User\Application\CpgUserRegistrar;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\UsernameAlreadyUsedException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CpgUserRegistrarTest extends TestCase
{
    public function testRegisterHashesPasswordAndPersistsUser(): void
    {
        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findOneByUsername')
            ->with('jane')
            ->willReturn(null);
        $repository->expects(self::once())
            ->method('save')
            ->with(self::isInstanceOf(CpgUser::class));

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects(self::once())
            ->method('hashPassword')
            ->with(self::isInstanceOf(CpgUser::class), 'plain-password')
            ->willReturn('hashed-password');

        $registrar = new CpgUserRegistrar($repository, $hasher);

        $user = $registrar->register('jane', 'plain-password');

        self::assertSame('jane', $user->getUsername());
        self::assertSame('hashed-password', $user->getPassword());
        self::assertSame(['ROLE_USER'], $user->getRoles());
    }

    public function testRegisterAssignsGivenRoles(): void
    {
        $repository = $this->createStub(CpgUserRepositoryInterface::class);
        $repository->method('findOneByUsername')->willReturn(null);

        $hasher = $this->createStub(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed-password');

        $registrar = new CpgUserRegistrar($repository, $hasher);

        $user = $registrar->register('super', 'plain-password', [CpgUser::ROLE_SUPER]);

        self::assertSame([CpgUser::ROLE_SUPER, 'ROLE_USER'], $user->getRoles());
    }

    public function testRegisterThrowsWhenUsernameAlreadyUsed(): void
    {
        $existingUser = new CpgUser('jane', 'hashed-password');

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneByUsername')->with('jane')->willReturn($existingUser);
        $repository->expects(self::never())->method('save');

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects(self::never())->method('hashPassword');

        $registrar = new CpgUserRegistrar($repository, $hasher);

        $this->expectException(UsernameAlreadyUsedException::class);

        $registrar->register('jane', 'plain-password');
    }
}
