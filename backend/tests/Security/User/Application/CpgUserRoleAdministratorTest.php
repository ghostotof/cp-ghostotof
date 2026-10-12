<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Application;

use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\User\Application\AccountAdministrationLockInterface;
use App\Security\User\Application\CpgUserRoleAdministrator;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\ActingAccountNoLongerSuperAdminException;
use App\Security\User\Domain\Exception\CannotDemoteLastSuperAdminException;
use App\Security\User\Domain\Exception\CannotModifyOwnRolesException;
use App\Security\User\Domain\Exception\CpgUserNotFoundException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $repository->expects(self::never())->method('countOtherSuperAdminsAbleToSignIn');
        $repository->expects(self::once())->method('save')->with($target);

        $this->administrator($repository, $this->auditLoggerExpectingRoleChange($target, true))->setSuperAdmin($target->getId(), true, $actingUser);

        self::assertContains(CpgUser::ROLE_SUPER, $target->getRoles());
    }

    public function testRevokeSuperAdminFromAnInvitedAccountKeepsRoleTrusted(): void
    {
        $actingUser = $this->user('super');
        $target = $this->superUser('other-super');
        $target->setEmail('other-super@example.test');

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with($target->getId())->willReturn($target);
        $repository->expects(self::once())->method('countOtherSuperAdminsAbleToSignIn')->with($target)->willReturn(1);
        $repository->expects(self::once())->method('save')->with($target);

        $this->administrator($repository, $this->auditLoggerExpectingRoleChange($target, false))->setSuperAdmin($target->getId(), false, $actingUser);

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
        $repository->expects(self::once())->method('countOtherSuperAdminsAbleToSignIn')->with($target)->willReturn(1);
        $repository->expects(self::once())->method('save')->with($target);

        $this->administrator($repository, $this->auditLoggerExpectingRoleChange($target, false))->setSuperAdmin($target->getId(), false, $actingUser);

        self::assertSame(['ROLE_USER'], $target->getRoles());
    }

    /**
     * Issue #551 : la garde compte les **autres** ROLE_SUPER capables de se
     * connecter, la cible exclue — ici aucun, un seul autre en attente
     * d'activation par exemple.
     */
    public function testRevokeThrowsWhenNoOtherSuperAdminAbleToSignInRemains(): void
    {
        $actingUser = $this->user('super');
        $target = $this->superUser('other-super');

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with($target->getId())->willReturn($target);
        $repository->expects(self::once())->method('countOtherSuperAdminsAbleToSignIn')->with($target)->willReturn(0);
        $repository->expects(self::never())->method('save');

        $this->expectException(CannotDemoteLastSuperAdminException::class);

        $this->administrator($repository, $this->auditLoggerExpectingNothing())->setSuperAdmin($target->getId(), false, $actingUser);
    }

    /**
     * Issues #551, #552 : l'état « déjà atteint », la garde et l'écriture se
     * lisent et se font sous le verrou d'administration, sur le compte qu'il
     * a relu ; le journal s'écrit après son retour, donc après le COMMIT.
     */
    public function testTheChangeRunsUnderTheAdministrationLockOnTheRereadAccountAndLogsAfterItReturned(): void
    {
        $actingUser = $this->user('super');
        $target = $this->user('jane');
        $reread = $this->user('jane');
        $lockReturned = false;

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->method('findOneById')->willReturn($target);
        $repository->expects(self::once())->method('save')->with($reread);

        $lock = $this->createMock(AccountAdministrationLockInterface::class);
        $lock->expects(self::once())->method('whileAdministering')->with($target, $actingUser)->willReturnCallback(
            static function (CpgUser $target, CpgUser $actor, Closure $operation) use ($reread, &$lockReturned): mixed {
                $result = $operation($reread);
                $lockReturned = true;

                return $result;
            },
        );

        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('roleChanged')->with($target, true)->willReturnCallback(static function () use (&$lockReturned): void {
            self::assertTrue($lockReturned, 'Le journal a été écrit avant le retour du verrou, donc avant le COMMIT.');
        });

        $this->administrator($repository, $auditLogger, $lock)->setSuperAdmin($target->getId(), true, $actingUser);

        self::assertContains(CpgUser::ROLE_SUPER, $reread->getRoles());
        self::assertNotContains(CpgUser::ROLE_SUPER, $target->getRoles(), 'C\'est l\'instance d\'avant le verrou qui a été modifiée.');
    }

    /**
     * L'état « déjà atteint » se lit sur le compte relu, pas sur celui
     * d'avant le verrou : promu entre-temps, un compte à promouvoir n'a plus
     * rien à changer, et le journal ne dit rien.
     */
    public function testTheIdempotentExitIsReadOnTheRereadAccount(): void
    {
        $actingUser = $this->user('super');
        $target = $this->user('jane');
        $rereadAlreadySuper = $this->superUser('jane');

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->method('findOneById')->willReturn($target);
        $repository->expects(self::never())->method('save');

        $lock = self::createStub(AccountAdministrationLockInterface::class);
        $lock->method('whileAdministering')->willReturnCallback(
            static fn (CpgUser $target, CpgUser $actor, Closure $operation): mixed => $operation($rereadAlreadySuper),
        );

        $this->administrator($repository, $this->auditLoggerExpectingNothing(), $lock)->setSuperAdmin($target->getId(), true, $actingUser);
    }

    /**
     * @return iterable<string, array{Closure(): never}>
     */
    public static function lockRefusals(): iterable
    {
        yield 'compte administré disparu (#552)' => [static fn (): never => throw CpgUserNotFoundException::forId(Uuid::v7())];
        yield 'acteur rétrogradé entre-temps (#551)' => [static fn (): never => throw ActingAccountNoLongerSuperAdminException::demotedMeanwhile()];
    }

    /**
     * @param Closure(): never $refusal
     */
    #[DataProvider('lockRefusals')]
    public function testARefusalOfTheLockSavesNothingAndLogsNothing(Closure $refusal): void
    {
        $actingUser = $this->user('super');
        $target = $this->user('jane');

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->method('findOneById')->willReturn($target);
        $repository->expects(self::never())->method('save');

        $lock = self::createStub(AccountAdministrationLockInterface::class);
        $lock->method('whileAdministering')->willReturnCallback($refusal);

        try {
            $this->administrator($repository, $this->auditLoggerExpectingNothing(), $lock)->setSuperAdmin($target->getId(), true, $actingUser);
            self::fail('Le refus du verrou aurait dû remonter.');
        } catch (CpgUserNotFoundException|ActingAccountNoLongerSuperAdminException) {
        }
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

        $this->administrator($repository, $this->auditLoggerExpectingNothing())->setSuperAdmin($sameIdFromTheUrl, false, $actingUser);
    }

    public function testThrowsWhenUserNotFound(): void
    {
        $actingUser = $this->user('super');

        $repository = self::createStub(CpgUserRepositoryInterface::class);
        $repository->method('findOneById')->willReturn(null);

        $this->expectException(CpgUserNotFoundException::class);

        $this->administrator($repository, $this->auditLoggerExpectingNothing())->setSuperAdmin(Uuid::v7(), true, $actingUser);
    }

    public function testGrantIsIdempotentWhenTheUserIsAlreadySuperAdmin(): void
    {
        $actingUser = $this->user('super');
        $target = $this->superUser('other-super');

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with($target->getId())->willReturn($target);
        $repository->expects(self::never())->method('countOtherSuperAdminsAbleToSignIn');
        $repository->expects(self::never())->method('save');

        $this->administrator($repository, $this->auditLoggerExpectingNothing())->setSuperAdmin($target->getId(), true, $actingUser);
    }

    public function testRevokeIsIdempotentWhenTheUserIsNotSuperAdmin(): void
    {
        // Cible sans ROLE_SUPER : la garde du dernier super ne doit pas être consultée.
        $actingUser = $this->user('super');
        $target = $this->user('jane');

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with($target->getId())->willReturn($target);
        $repository->expects(self::never())->method('countOtherSuperAdminsAbleToSignIn');
        $repository->expects(self::never())->method('save');

        $this->administrator($repository, $this->auditLoggerExpectingNothing())->setSuperAdmin($target->getId(), false, $actingUser);
    }

    /**
     * Le verrou d'administration exécute l'opération telle quelle, sur le
     * compte reçu ; le verrou réel est couvert par
     * PostgresAccountAdministrationLockTest et AccountAdministrationConcurrencyTest.
     */
    private function administrator(
        CpgUserRepositoryInterface $repository,
        SecurityAuditLoggerInterface $auditLogger,
        ?AccountAdministrationLockInterface $administrationLock = null,
    ): CpgUserRoleAdministrator {
        if (null === $administrationLock) {
            $administrationLock = self::createStub(AccountAdministrationLockInterface::class);
            $administrationLock->method('whileAdministering')->willReturnCallback(
                static fn (CpgUser $target, CpgUser $actingUser, Closure $operation): mixed => $operation($target),
            );
        }

        return new CpgUserRoleAdministrator($repository, $auditLogger, $administrationLock);
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
