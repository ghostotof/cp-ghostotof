<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Application;

use App\Security\User\Application\CpgUserRoleAdministrator;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\CannotDemoteLastSuperAdminException;
use App\Security\User\Domain\Exception\CannotModifyOwnRolesException;
use App\Security\User\Domain\Exception\CpgUserNotFoundException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class CpgUserRoleAdministratorTest extends TestCase
{
    public function testGrantSuperAdminToAPlainUser(): void
    {
        $actingUser = $this->userWithId(1, 'super');
        $target = $this->userWithId(2, 'jane');

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with(2)->willReturn($target);
        $repository->expects(self::never())->method('countByRole');
        $repository->expects(self::once())->method('save')->with($target);

        (new CpgUserRoleAdministrator($repository))->setSuperAdmin(2, true, $actingUser);

        self::assertContains(CpgUser::ROLE_SUPER, $target->getRoles());
    }

    public function testRevokeSuperAdminFromAnInvitedAccountKeepsRoleTrusted(): void
    {
        $actingUser = $this->userWithId(1, 'super');
        $target = $this->superUserWithId(2, 'other-super');
        $target->setEmail('other-super@example.test');

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with(2)->willReturn($target);
        $repository->expects(self::once())->method('countByRole')->with(CpgUser::ROLE_SUPER)->willReturn(2);
        $repository->expects(self::once())->method('save')->with($target);

        (new CpgUserRoleAdministrator($repository))->setSuperAdmin(2, false, $actingUser);

        self::assertNotContains(CpgUser::ROLE_SUPER, $target->getRoles());
        // ADR 0003 D1 : un compte invité a été accordé nominativement (son
        // e-mail dit à qui le CV est ouvert) — la rétrogradation le ramène à
        // son palier réel, ROLE_TRUSTED, jamais au palier de base.
        self::assertContains(CpgUser::ROLE_TRUSTED, $target->getRoles());
    }

    /**
     * Issue #78, pt 3. Un compte CLI n'a pas d'e-mail : personne ne l'a
     * jamais accordé nominativement (Task 12 refuse `--role ROLE_TRUSTED` à
     * la CLI, précisément pour ça). Le promouvoir ROLE_SUPER puis le
     * rétrograder ne doit pas être une voie détournée vers le CV : il
     * retombe au palier de base.
     */
    public function testRevokeSuperAdminFromAnAccountWithoutEmailDropsItToTheBaseTier(): void
    {
        $actingUser = $this->userWithId(1, 'super');
        $target = $this->superUserWithId(2, 'cli-super');

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with(2)->willReturn($target);
        $repository->expects(self::once())->method('countByRole')->with(CpgUser::ROLE_SUPER)->willReturn(2);
        $repository->expects(self::once())->method('save')->with($target);

        (new CpgUserRoleAdministrator($repository))->setSuperAdmin(2, false, $actingUser);

        self::assertSame(['ROLE_USER'], $target->getRoles());
    }

    public function testRevokeThrowsWhenTargetIsTheLastSuperAdmin(): void
    {
        $actingUser = $this->userWithId(1, 'super');
        $target = $this->superUserWithId(2, 'other-super');

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with(2)->willReturn($target);
        $repository->expects(self::once())->method('countByRole')->with(CpgUser::ROLE_SUPER)->willReturn(1);
        $repository->expects(self::never())->method('save');

        $this->expectException(CannotDemoteLastSuperAdminException::class);

        (new CpgUserRoleAdministrator($repository))->setSuperAdmin(2, false, $actingUser);
    }

    public function testCannotModifyOwnRoles(): void
    {
        $actingUser = $this->userWithId(1, 'super');

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::never())->method('findOneById');
        $repository->expects(self::never())->method('save');

        $this->expectException(CannotModifyOwnRolesException::class);

        (new CpgUserRoleAdministrator($repository))->setSuperAdmin(1, false, $actingUser);
    }

    public function testThrowsWhenUserNotFound(): void
    {
        $actingUser = $this->userWithId(1, 'super');

        $repository = self::createStub(CpgUserRepositoryInterface::class);
        $repository->method('findOneById')->willReturn(null);

        $this->expectException(CpgUserNotFoundException::class);

        (new CpgUserRoleAdministrator($repository))->setSuperAdmin(2, true, $actingUser);
    }

    public function testGrantIsIdempotentWhenTheUserIsAlreadySuperAdmin(): void
    {
        $actingUser = $this->userWithId(1, 'super');
        $target = $this->superUserWithId(2, 'other-super');

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with(2)->willReturn($target);
        $repository->expects(self::never())->method('countByRole');
        $repository->expects(self::never())->method('save');

        (new CpgUserRoleAdministrator($repository))->setSuperAdmin(2, true, $actingUser);
    }

    public function testRevokeIsIdempotentWhenTheUserIsNotSuperAdmin(): void
    {
        // Cible sans ROLE_SUPER : la garde du dernier super ne doit pas être consultée.
        $actingUser = $this->userWithId(1, 'super');
        $target = $this->userWithId(2, 'jane');

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with(2)->willReturn($target);
        $repository->expects(self::never())->method('countByRole');
        $repository->expects(self::never())->method('save');

        (new CpgUserRoleAdministrator($repository))->setSuperAdmin(2, false, $actingUser);
    }

    private function userWithId(int $id, string $username): CpgUser
    {
        $user = new CpgUser($username, 'hashed-password');

        $reflection = new \ReflectionProperty(CpgUser::class, 'id');
        $reflection->setValue($user, $id);

        return $user;
    }

    private function superUserWithId(int $id, string $username): CpgUser
    {
        $user = $this->userWithId($id, $username);
        $user->setRoles([CpgUser::ROLE_SUPER]);

        return $user;
    }
}
