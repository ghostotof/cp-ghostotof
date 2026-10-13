<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Application;

use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\User\Application\AccountAdministrationLockInterface;
use App\Security\User\Application\AccountLockInterface;
use App\Security\User\Application\CpgUserAdministrator;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\ActingAccountNoLongerSuperAdminException;
use App\Security\User\Domain\Exception\CannotDeleteLastSuperAdminException;
use App\Security\User\Domain\Exception\CannotDeleteOwnAccountException;
use App\Security\User\Domain\Exception\CpgUserNotFoundException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Security\User\Domain\Repository\PasswordSetupTokenRepositoryInterface;
use App\Tests\Support\TestCredentials;
use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

final class CpgUserAdministratorTest extends TestCase
{
    public function testDeleteRemovesAnotherUser(): void
    {
        $actingUser = $this->user('super');
        $targetUser = $this->user('jane');

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with($targetUser->getId())->willReturn($targetUser);
        // Cible sans ROLE_SUPER : la garde anti-lockout ne doit même pas
        // interroger le dépôt sur le décompte.
        $repository->expects(self::never())->method('countOtherSuperAdminsAbleToSignIn');
        $repository->expects(self::once())->method('remove')->with($targetUser);

        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('userDeleted')->with($targetUser);

        $administrator = $this->administrator($repository, self::createStub(UserPasswordHasherInterface::class), $auditLogger);

        $administrator->delete($targetUser->getId(), $actingUser);
    }

    /**
     * Issue #551 : la garde compte les **autres** ROLE_SUPER capables de se
     * connecter, la cible exclue — ici aucun, un seul autre en attente
     * d'activation par exemple.
     */
    public function testDeleteThrowsWhenNoOtherSuperAdminAbleToSignInRemains(): void
    {
        $actingUser = $this->user('super');
        $targetUser = $this->superUser('other-super');

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with($targetUser->getId())->willReturn($targetUser);
        $repository->expects(self::once())->method('countOtherSuperAdminsAbleToSignIn')->with($targetUser)->willReturn(0);
        $repository->expects(self::never())->method('remove');

        $administrator = $this->administrator($repository, self::createStub(UserPasswordHasherInterface::class), $this->auditLoggerExpectingNothing());

        $this->expectException(CannotDeleteLastSuperAdminException::class);

        $administrator->delete($targetUser->getId(), $actingUser);
    }

    public function testDeleteRemovesSuperAdminWhenAnotherSuperAdminRemains(): void
    {
        $actingUser = $this->user('super');
        $targetUser = $this->superUser('other-super');

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with($targetUser->getId())->willReturn($targetUser);
        $repository->expects(self::once())->method('countOtherSuperAdminsAbleToSignIn')->with($targetUser)->willReturn(1);
        $repository->expects(self::once())->method('remove')->with($targetUser);

        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('userDeleted')->with($targetUser);

        $administrator = $this->administrator($repository, self::createStub(UserPasswordHasherInterface::class), $auditLogger);

        $administrator->delete($targetUser->getId(), $actingUser);
    }

    /**
     * Issues #551, #552 : la suppression, et la garde, se font sous le verrou
     * d'administration, sur le compte qu'il a relu ; le journal s'écrit après
     * son retour, donc après le COMMIT — une action journalisée est une action
     * validée.
     */
    public function testDeleteRunsUnderTheAdministrationLockAndLogsAfterItReturned(): void
    {
        $actingUser = $this->user('super');
        $targetUser = $this->superUser('other-super');
        $reread = $this->superUser('other-super');
        $lockReturned = false;

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->method('findOneById')->willReturn($targetUser);
        $repository->expects(self::once())->method('countOtherSuperAdminsAbleToSignIn')->with($reread)->willReturn(1);
        $repository->expects(self::once())->method('remove')->with($reread);

        $lock = $this->createMock(AccountAdministrationLockInterface::class);
        $lock->expects(self::once())->method('whileAdministering')->with($targetUser, $actingUser)->willReturnCallback(
            static function (CpgUser $target, CpgUser $actor, Closure $operation) use ($reread, &$lockReturned): mixed {
                $result = $operation($reread);
                $lockReturned = true;

                return $result;
            },
        );

        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('userDeleted')->willReturnCallback(static function () use (&$lockReturned): void {
            self::assertTrue($lockReturned, 'Le journal a été écrit avant le retour du verrou, donc avant le COMMIT.');
        });

        $administrator = $this->administrator($repository, self::createStub(UserPasswordHasherInterface::class), $auditLogger, administrationLock: $lock);

        $administrator->delete($targetUser->getId(), $actingUser);
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
    public function testARefusalOfTheLockRemovesNothingAndLogsNothing(Closure $refusal): void
    {
        $actingUser = $this->user('super');
        $targetUser = $this->user('jane');

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->method('findOneById')->willReturn($targetUser);
        $repository->expects(self::never())->method('remove');

        $lock = self::createStub(AccountAdministrationLockInterface::class);
        $lock->method('whileAdministering')->willReturnCallback($refusal);

        $administrator = $this->administrator($repository, self::createStub(UserPasswordHasherInterface::class), $this->auditLoggerExpectingNothing(), administrationLock: $lock);

        try {
            $administrator->delete($targetUser->getId(), $actingUser);
            self::fail('Le refus du verrou aurait dû remonter.');
        } catch (CpgUserNotFoundException|ActingAccountNoLongerSuperAdminException) {
        }
    }

    /**
     * La garde porte sur une *valeur* d'identifiant, pas sur une instance :
     * l'id vient de l'URL, donc d'un `Uuid` fraîchement reconstruit. Un `===`
     * entre deux objets `Uuid` de même valeur répondrait faux et laisserait
     * un super-admin se supprimer lui-même — d'où la comparaison par
     * `equals()`, et ce test qui la pin.
     */
    public function testDeleteThrowsWhenTargetingOwnAccount(): void
    {
        $actingUser = $this->user('super');
        $sameIdFromTheUrl = Uuid::fromString($actingUser->getId()->toRfc4122());

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::never())->method('findOneById');
        $repository->expects(self::never())->method('remove');

        $administrator = $this->administrator($repository, self::createStub(UserPasswordHasherInterface::class), $this->auditLoggerExpectingNothing());

        $this->expectException(CannotDeleteOwnAccountException::class);

        $administrator->delete($sameIdFromTheUrl, $actingUser);
    }

    public function testDeleteThrowsWhenUserNotFound(): void
    {
        $actingUser = $this->user('super');

        $repository = self::createStub(CpgUserRepositoryInterface::class);
        $repository->method('findOneById')->willReturn(null);

        $administrator = $this->administrator($repository, self::createStub(UserPasswordHasherInterface::class), $this->auditLoggerExpectingNothing());

        $this->expectException(CpgUserNotFoundException::class);

        $administrator->delete(Uuid::v7(), $actingUser);
    }

    public function testChangePasswordHashesAndSavesNewPassword(): void
    {
        $user = $this->user('jane');

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with($user->getId())->willReturn($user);
        $repository->expects(self::once())->method('save')->with($user);

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects(self::once())
            ->method('hashPassword')
            ->with($user, TestCredentials::variant('new'))
            ->willReturn('new-hashed-password');

        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('passwordChanged')->with($user);

        $administrator = $this->administrator($repository, $hasher, $auditLogger);

        $administrator->changePassword($user->getId(), TestCredentials::variant('new'));

        self::assertSame('new-hashed-password', $user->getPassword());
    }

    public function testChangePasswordThrowsWhenUserNotFound(): void
    {
        $repository = self::createStub(CpgUserRepositoryInterface::class);
        $repository->method('findOneById')->willReturn(null);

        $hasher = self::createStub(UserPasswordHasherInterface::class);

        $administrator = $this->administrator($repository, $hasher, $this->auditLoggerExpectingNothing());

        $this->expectException(CpgUserNotFoundException::class);

        $administrator->changePassword(Uuid::v7(), TestCredentials::variant('new'));
    }

    /**
     * Issue #460 (décision du 2026-10-11) : un mot de passe posé par l'admin
     * sur un compte encore en attente d'activation fait foi, le lien
     * d'invitation en circulation meurt — c'est le cas utile quand le lien est
     * douteux (e-mail transféré, boîte compromise).
     */
    public function testAPasswordSetOnAPendingAccountRevokesItsInvitationLinks(): void
    {
        $user = new CpgUser('newcomer', '');
        $user->markInvited(new DateTimeImmutable('2026-10-01 10:00:00'));
        $tokenRepository = $this->createMock(PasswordSetupTokenRepositoryInterface::class);
        $tokenRepository->expects(self::once())->method('deleteUnusedForUser')->with($user);

        $this->administrator($this->repositoryFinding($user), $this->hasher(), self::createStub(SecurityAuditLoggerInterface::class), $tokenRepository)
            ->changePassword($user->getId(), TestCredentials::variant('new'));
    }

    /**
     * @return iterable<string, array{CpgUser}>
     */
    public static function accountsWithoutPendingInvitation(): iterable
    {
        $active = new CpgUser('active', 'hashed-password');
        $active->markInvited(new DateTimeImmutable('2026-10-01 10:00:00'));
        $active->markActivated(new DateTimeImmutable('2026-10-02 10:00:00'));

        yield 'compte activé' => [$active];
        yield 'compte créé en ligne de commande' => [new CpgUser('console', 'hashed-password')];
    }

    #[DataProvider('accountsWithoutPendingInvitation')]
    public function testAPasswordSetOnAnAccountWithoutPendingInvitationRevokesNothing(CpgUser $user): void
    {
        $tokenRepository = $this->createMock(PasswordSetupTokenRepositoryInterface::class);
        $tokenRepository->expects(self::never())->method('deleteUnusedForUser');

        $this->administrator($this->repositoryFinding($user), $this->hasher(), self::createStub(SecurityAuditLoggerInterface::class), $tokenRepository)
            ->changePassword($user->getId(), TestCredentials::variant('new'));
    }

    private function repositoryFinding(CpgUser $user): CpgUserRepositoryInterface
    {
        $repository = self::createStub(CpgUserRepositoryInterface::class);
        $repository->method('findOneById')->willReturn($user);

        return $repository;
    }

    private function hasher(): UserPasswordHasherInterface
    {
        $hasher = self::createStub(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('new-hashed-password');

        return $hasher;
    }

    /**
     * Les deux verrous exécutent l'opération telle quelle, sur le compte
     * reçu ; les verrous réels sont couverts par PostgresAccountRowLockTest,
     * PostgresAccountAdministrationLockTest et les tests de concurrence.
     */
    private function administrator(
        CpgUserRepositoryInterface $repository,
        UserPasswordHasherInterface $hasher,
        SecurityAuditLoggerInterface $auditLogger,
        ?PasswordSetupTokenRepositoryInterface $tokenRepository = null,
        ?AccountAdministrationLockInterface $administrationLock = null,
    ): CpgUserAdministrator {
        $accountLock = self::createStub(AccountLockInterface::class);
        $accountLock->method('whileLocked')->willReturnCallback(
            static fn (CpgUser $user, Closure $operation): mixed => $operation($user),
        );
        if (null === $administrationLock) {
            $administrationLock = self::createStub(AccountAdministrationLockInterface::class);
            $administrationLock->method('whileAdministering')->willReturnCallback(
                static fn (CpgUser $target, CpgUser $actingUser, Closure $operation): mixed => $operation($target),
            );
        }

        return new CpgUserAdministrator($repository, $hasher, $auditLogger, $tokenRepository ?? self::createStub(PasswordSetupTokenRepositoryInterface::class), $accountLock, $administrationLock);
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
     * Journal de sécurité (D5) : sur un refus, rien n'a eu lieu.
     */
    private function auditLoggerExpectingNothing(): SecurityAuditLoggerInterface&MockObject
    {
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('userDeleted');
        $auditLogger->expects(self::never())->method('passwordChanged');

        return $auditLogger;
    }
}
