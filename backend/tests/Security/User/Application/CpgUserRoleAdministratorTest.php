<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Application;

use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\User\Application\CpgUserRoleAdministrator;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\CannotDemoteLastSuperAdminException;
use App\Security\User\Domain\Exception\CannotModifyOwnRolesException;
use App\Security\User\Domain\Exception\CpgUserNotFoundException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class CpgUserRoleAdministratorTest extends TestCase
{
    public function testGrantSuperAdminToAPlainUser(): void
    {
        $actingUser = $this->user('super');
        $target = $this->user('jane');

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with($target->getId())->willReturn($target);
        $repository->expects(self::never())->method('countByRole');
        $repository->expects(self::once())->method('save')->with($target);

        (new CpgUserRoleAdministrator($repository, $this->auditLoggerExpectingRoleChange($target, true)))->setSuperAdmin($target->getId(), true, $actingUser);

        self::assertContains(CpgUser::ROLE_SUPER, $target->getRoles());
    }

    public function testRevokeSuperAdminFromAnInvitedAccountKeepsRoleTrusted(): void
    {
        $actingUser = $this->user('super');
        $target = $this->superUser('other-super');
        $target->setEmail('other-super@example.test');

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with($target->getId())->willReturn($target);
        $repository->expects(self::once())->method('countByRole')->with(CpgUser::ROLE_SUPER)->willReturn(2);
        $repository->expects(self::once())->method('save')->with($target);

        (new CpgUserRoleAdministrator($repository, $this->auditLoggerExpectingRoleChange($target, false)))->setSuperAdmin($target->getId(), false, $actingUser);

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
        $actingUser = $this->user('super');
        $target = $this->superUser('cli-super');

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with($target->getId())->willReturn($target);
        $repository->expects(self::once())->method('countByRole')->with(CpgUser::ROLE_SUPER)->willReturn(2);
        $repository->expects(self::once())->method('save')->with($target);

        (new CpgUserRoleAdministrator($repository, $this->auditLoggerExpectingRoleChange($target, false)))->setSuperAdmin($target->getId(), false, $actingUser);

        self::assertSame(['ROLE_USER'], $target->getRoles());
    }

    public function testRevokeThrowsWhenTargetIsTheLastSuperAdmin(): void
    {
        $actingUser = $this->user('super');
        $target = $this->superUser('other-super');

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with($target->getId())->willReturn($target);
        $repository->expects(self::once())->method('countByRole')->with(CpgUser::ROLE_SUPER)->willReturn(1);
        $repository->expects(self::never())->method('save');

        $this->expectException(CannotDemoteLastSuperAdminException::class);

        (new CpgUserRoleAdministrator($repository, $this->auditLoggerExpectingNothing()))->setSuperAdmin($target->getId(), false, $actingUser);
    }

    /**
     * Même garde que CpgUserAdministratorTest::testDeleteThrowsWhenTargetingOwnAccount :
     * l'identifiant vient de l'URL, donc d'un `Uuid` reconstruit. Un `===`
     * entre deux instances de même valeur laisserait un super-admin se
     * rétrograder lui-même.
     */
    public function testCannotModifyOwnRoles(): void
    {
        $actingUser = $this->user('super');
        $sameIdFromTheUrl = Uuid::fromString($actingUser->getId()->toRfc4122());

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::never())->method('findOneById');
        $repository->expects(self::never())->method('save');

        $this->expectException(CannotModifyOwnRolesException::class);

        (new CpgUserRoleAdministrator($repository, $this->auditLoggerExpectingNothing()))->setSuperAdmin($sameIdFromTheUrl, false, $actingUser);
    }

    public function testThrowsWhenUserNotFound(): void
    {
        $actingUser = $this->user('super');

        $repository = self::createStub(CpgUserRepositoryInterface::class);
        $repository->method('findOneById')->willReturn(null);

        $this->expectException(CpgUserNotFoundException::class);

        (new CpgUserRoleAdministrator($repository, $this->auditLoggerExpectingNothing()))->setSuperAdmin(Uuid::v7(), true, $actingUser);
    }

    public function testGrantIsIdempotentWhenTheUserIsAlreadySuperAdmin(): void
    {
        $actingUser = $this->user('super');
        $target = $this->superUser('other-super');

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with($target->getId())->willReturn($target);
        $repository->expects(self::never())->method('countByRole');
        $repository->expects(self::never())->method('save');

        (new CpgUserRoleAdministrator($repository, $this->auditLoggerExpectingNothing()))->setSuperAdmin($target->getId(), true, $actingUser);
    }

    public function testRevokeIsIdempotentWhenTheUserIsNotSuperAdmin(): void
    {
        // Cible sans ROLE_SUPER : la garde du dernier super ne doit pas être consultée.
        $actingUser = $this->user('super');
        $target = $this->user('jane');

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with($target->getId())->willReturn($target);
        $repository->expects(self::never())->method('countByRole');
        $repository->expects(self::never())->method('save');

        (new CpgUserRoleAdministrator($repository, $this->auditLoggerExpectingNothing()))->setSuperAdmin($target->getId(), false, $actingUser);
    }

    /**
     * Plus de réflexion sur `id` : depuis la spec 0003, le constructeur pose
     * lui-même un UUID v7, distinct pour chaque instance.
     */
    private function user(string $username): CpgUser
    {
        return new CpgUser($username, 'hashed-password');
    }

    private function superUser(string $username): CpgUser
    {
        $user = $this->user($username);
        $user->setRoles([CpgUser::ROLE_SUPER]);

        return $user;
    }

    /**
     * Journal de sécurité (D5) : une ligne `role-changed` par changement
     * effectif, avec le sens (accordé / retiré).
     */
    private function auditLoggerExpectingRoleChange(CpgUser $target, bool $superAdmin): SecurityAuditLoggerInterface&MockObject
    {
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('roleChanged')->with($target, $superAdmin);

        return $auditLogger;
    }

    /**
     * Refus ou état déjà atteint : rien n'a changé, le journal ne dit rien —
     * une ligne « rôle changé » sans changement serait un mensonge.
     */
    private function auditLoggerExpectingNothing(): SecurityAuditLoggerInterface&MockObject
    {
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('roleChanged');

        return $auditLogger;
    }
}
