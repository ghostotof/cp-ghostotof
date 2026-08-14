<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Application;

use App\Security\User\Application\CpgUserAdministrator;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\CannotDeleteOwnAccountException;
use App\Security\User\Domain\Exception\CpgUserNotFoundException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CpgUserAdministratorTest extends TestCase
{
    public function testDeleteRemovesAnotherUser(): void
    {
        $actingUser = $this->userWithId(1, 'super');
        $targetUser = $this->userWithId(2, 'jane');

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with(2)->willReturn($targetUser);
        $repository->expects(self::once())->method('remove')->with($targetUser);

        $administrator = new CpgUserAdministrator($repository, $this->createStub(UserPasswordHasherInterface::class));

        $administrator->delete(2, $actingUser);
    }

    public function testDeleteThrowsWhenTargetingOwnAccount(): void
    {
        $actingUser = $this->userWithId(1, 'super');

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::never())->method('findOneById');
        $repository->expects(self::never())->method('remove');

        $administrator = new CpgUserAdministrator($repository, $this->createStub(UserPasswordHasherInterface::class));

        $this->expectException(CannotDeleteOwnAccountException::class);

        $administrator->delete(1, $actingUser);
    }

    public function testDeleteThrowsWhenUserNotFound(): void
    {
        $actingUser = $this->userWithId(1, 'super');

        $repository = $this->createStub(CpgUserRepositoryInterface::class);
        $repository->method('findOneById')->willReturn(null);

        $administrator = new CpgUserAdministrator($repository, $this->createStub(UserPasswordHasherInterface::class));

        $this->expectException(CpgUserNotFoundException::class);

        $administrator->delete(2, $actingUser);
    }

    public function testChangePasswordHashesAndSavesNewPassword(): void
    {
        $user = $this->userWithId(2, 'jane');

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with(2)->willReturn($user);
        $repository->expects(self::once())->method('save')->with($user);

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects(self::once())
            ->method('hashPassword')
            ->with($user, 'NewSecurePassword123')
            ->willReturn('new-hashed-password');

        $administrator = new CpgUserAdministrator($repository, $hasher);

        $administrator->changePassword(2, 'NewSecurePassword123');

        self::assertSame('new-hashed-password', $user->getPassword());
    }

    public function testChangePasswordThrowsWhenUserNotFound(): void
    {
        $repository = $this->createStub(CpgUserRepositoryInterface::class);
        $repository->method('findOneById')->willReturn(null);

        $hasher = $this->createStub(UserPasswordHasherInterface::class);

        $administrator = new CpgUserAdministrator($repository, $hasher);

        $this->expectException(CpgUserNotFoundException::class);

        $administrator->changePassword(2, 'NewSecurePassword123');
    }

    private function userWithId(int $id, string $username): CpgUser
    {
        $user = new CpgUser($username, 'hashed-password');

        $reflection = new \ReflectionProperty(CpgUser::class, 'id');
        $reflection->setValue($user, $id);

        return $user;
    }
}
