<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Application;

use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\User\Application\AccountAdministrationLockInterface;
use App\Security\User\Application\AccountLockInterface;
use App\Security\User\Application\CpgUserAdministrator;
use App\Security\User\Application\DeadlockRetryInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\ActingAccountNoLongerSuperAdminException;
use App\Security\User\Domain\Exception\CannotDeleteLastSuperAdminException;
use App\Security\User\Domain\Exception\CannotDeleteOwnAccountException;
use App\Security\User\Domain\Exception\CpgUserNotFoundException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Security\User\Domain\Repository\PasswordSetupTokenRepositoryInterface;
use App\Security\User\Infrastructure\Doctrine\DoctrineDeadlockRetry;
use App\Shared\Application\Security\AccountAccessWithdrawal;
use App\Shared\Application\Security\AccountMcpConnectionsRevokerInterface;
use App\Shared\Application\Security\RevokedAccountMcpConnections;
use App\Tests\Support\DeadlockedStatement;
use App\Tests\Support\TestCredentials;
use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
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

    /**
     * F2 de la relecture de #650, S1 de la seconde : le changement de mot de passe
     * avance la version des identifiants du compte **relu sous le verrou** (le
     * double que le verrou rend à la place de celui du dépôt), pas du compte lu
     * avant — l'API refuse tout cookie de session qui porte l'ancienne version.
     */
    public function testChangePasswordAdvancesTheCredentialsVersionOfTheAccountReadUnderTheLock(): void
    {
        $user = $this->user('jane');
        $locked = clone $user;
        $accountLock = self::createStub(AccountLockInterface::class);
        $accountLock->method('whileLocked')->willReturnCallback(
            static fn (CpgUser $ignored, Closure $operation): mixed => $operation($locked),
        );

        $this->administrator($this->repositoryFinding($user), $this->hasher(), self::createStub(SecurityAuditLoggerInterface::class), accountLock: $accountLock)
            ->changePassword($user->getId(), TestCredentials::variant('new'));

        self::assertSame('new-hashed-password', $locked->getPassword());
        self::assertSame(1, $locked->getCredentialsVersion(), 'Le changement de mot de passe n\'a pas avancé la version des identifiants (#650, S1).');
        self::assertSame(0, $user->getCredentialsVersion(), 'La version a été avancée sur le compte d\'avant le verrou.');
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

    /**
     * #650, tâche 5 : le mot de passe et la révocation des connexions MCP du
     * compte valident ensemble. L'ordre observé : le verrou s'ouvre, le mot de
     * passe est écrit, `revokeAllOf()` est appelé **dans** la fermeture (sur le
     * compte relu sous le verrou, raison « mot de passe changé »), le verrou
     * se referme (COMMIT), puis seulement `passwordChanged()` et
     * `auditRevoked()` — avec ce que la révocation a rendu. Un audit passé
     * avant la fermeture du verrou décrirait une écriture que le COMMIT peut
     * encore défaire.
     */
    public function testChangePasswordRevokesTheMcpConnectionsInsideTheLockAndAuditsAfterIt(): void
    {
        $user = $this->user('jane');
        $steps = [];
        $revoked = new RevokedAccountMcpConnections(AccountAccessWithdrawal::PasswordChanged, []);

        $accountLock = self::createStub(AccountLockInterface::class);
        $accountLock->method('whileLocked')->willReturnCallback(
            static function (CpgUser $target, Closure $operation) use (&$steps): mixed {
                $steps[] = 'lock opened';
                $result = $operation($target);
                $steps[] = 'lock closed';

                return $result;
            },
        );
        $repository = $this->repositoryFinding($user);
        $mcpConnections = $this->createMock(AccountMcpConnectionsRevokerInterface::class);
        $mcpConnections->expects(self::once())->method('revokeAllOf')
            ->with($user, AccountAccessWithdrawal::PasswordChanged)
            ->willReturnCallback(static function () use (&$steps, $revoked): RevokedAccountMcpConnections {
                $steps[] = 'connections revoked';

                return $revoked;
            });
        $mcpConnections->expects(self::once())->method('auditRevoked')->with($revoked)
            ->willReturnCallback(static function () use (&$steps): void {
                $steps[] = 'connections audited';
            });
        $auditLogger = self::createStub(SecurityAuditLoggerInterface::class);
        $auditLogger->method('passwordChanged')->willReturnCallback(static function () use (&$steps): void {
            $steps[] = 'password audited';
        });

        $this->administrator($repository, $this->hasher(), $auditLogger, mcpConnections: $mcpConnections, accountLock: $accountLock)
            ->changePassword($user->getId(), TestCredentials::variant('new'));

        self::assertSame(['lock opened', 'connections revoked', 'lock closed', 'password audited', 'connections audited'], $steps);
    }

    /**
     * La révocation échoue dans la fermeture : l'exception sort, le verrou
     * annule (le mot de passe avec lui), et rien n'est audité — ni le mot de
     * passe, ni les connexions.
     */
    public function testARevocationFailureAuditsNothing(): void
    {
        $user = $this->user('jane');
        $mcpConnections = $this->createMock(AccountMcpConnectionsRevokerInterface::class);
        $mcpConnections->method('revokeAllOf')->willThrowException(new RuntimeException('révocation refusée'));
        $mcpConnections->expects(self::never())->method('auditRevoked');

        $this->expectException(RuntimeException::class);

        $this->administrator($this->repositoryFinding($user), $this->hasher(), $this->auditLoggerExpectingNothing(), mcpConnections: $mcpConnections)
            ->changePassword($user->getId(), TestCredentials::variant('new'));
    }

    /** Un compte disparu avant le verrou : 404, aucune révocation tentée ni auditée. */
    public function testAVanishedAccountRevokesNoConnection(): void
    {
        $repository = self::createStub(CpgUserRepositoryInterface::class);
        $repository->method('findOneById')->willReturn(null);
        $mcpConnections = $this->createMock(AccountMcpConnectionsRevokerInterface::class);
        $mcpConnections->expects(self::never())->method('revokeAllOf');
        $mcpConnections->expects(self::never())->method('auditRevoked');

        $this->expectException(CpgUserNotFoundException::class);

        $this->administrator($repository, self::createStub(UserPasswordHasherInterface::class), $this->auditLoggerExpectingNothing(), mcpConnections: $mcpConnections)
            ->changePassword(Uuid::v7(), TestCredentials::variant('new'));
    }

    /**
     * S3 (CR4) : l'interblocage F1 annule le changement de mot de passe, la
     * remédiation d'un mot de passe compromis ne doit pas échouer en silence.
     * Relancé **une fois**, sur le compte **relu** (l'EntityManager remis à neuf
     * a oublié l'ancien) : le hachage, calculé hors verrou, n'est pas recalculé,
     * et le journal ne s'écrit qu'une fois, au retour du second essai.
     */
    public function testADeadlockedPasswordChangeIsRetriedOnceOnTheAccountReadAgainAndAuditedOnce(): void
    {
        $stale = $this->user('jane');
        $fresh = clone $stale;
        $revoked = new RevokedAccountMcpConnections(AccountAccessWithdrawal::PasswordChanged, []);

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::exactly(2))->method('findOneById')->with($stale->getId())->willReturnOnConsecutiveCalls($stale, $fresh);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects(self::once())->method('hashPassword')->willReturn('new-hashed-password');
        $mcpConnections = $this->createMock(AccountMcpConnectionsRevokerInterface::class);
        $mcpConnections->expects(self::exactly(2))->method('revokeAllOf')->willReturnOnConsecutiveCalls(
            self::throwException(DeadlockedStatement::exception()),
            $revoked,
        );
        $mcpConnections->expects(self::once())->method('auditRevoked')->with($revoked);
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('passwordChanged')->with($fresh);

        $this->administrator($repository, $hasher, $auditLogger, mcpConnections: $mcpConnections)
            ->changePassword($stale->getId(), TestCredentials::variant('new'));

        self::assertSame('new-hashed-password', $fresh->getPassword());
        self::assertSame(1, $fresh->getCredentialsVersion(), 'La version des identifiants doit avancer une fois sur le compte relu.');
    }

    /** Deux interblocages d'affilée : l'exception remonte comme avant S3, rien n'est audité. */
    public function testAPasswordChangeDeadlockedTwiceFailsAndAuditsNothing(): void
    {
        $user = $this->user('jane');
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects(self::once())->method('hashPassword')->willReturn('new-hashed-password');
        $mcpConnections = $this->createMock(AccountMcpConnectionsRevokerInterface::class);
        $mcpConnections->expects(self::exactly(2))->method('revokeAllOf')->willThrowException(DeadlockedStatement::exception());
        $mcpConnections->expects(self::never())->method('auditRevoked');

        $this->expectException(DeadlockException::class);

        $this->administrator($this->repositoryFinding($user), $hasher, $this->auditLoggerExpectingNothing(), mcpConnections: $mcpConnections)
            ->changePassword($user->getId(), TestCredentials::variant('new'));
    }

    /** Une autre exception de la fermeture n'est jamais relancée : un seul essai. */
    public function testAPasswordChangeFailingOtherwiseIsNotRetried(): void
    {
        $user = $this->user('jane');
        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->willReturn($user);
        $mcpConnections = $this->createMock(AccountMcpConnectionsRevokerInterface::class);
        $mcpConnections->expects(self::once())->method('revokeAllOf')->willThrowException(DeadlockedStatement::otherDriverException());

        $this->expectException(DriverException::class);

        $this->administrator($repository, $this->hasher(), $this->auditLoggerExpectingNothing(), mcpConnections: $mcpConnections)
            ->changePassword($user->getId(), TestCredentials::variant('new'));
    }

    /** Le compte supprimé entre les deux essais : 404 au second, aucun audit. */
    public function testAnAccountDeletedBetweenTheTwoAttemptsOfAPasswordChangeIsNotFound(): void
    {
        $user = $this->user('jane');
        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::exactly(2))->method('findOneById')->willReturnOnConsecutiveCalls($user, null);
        $mcpConnections = $this->createMock(AccountMcpConnectionsRevokerInterface::class);
        $mcpConnections->expects(self::once())->method('revokeAllOf')->willThrowException(DeadlockedStatement::exception());

        $this->expectException(CpgUserNotFoundException::class);

        $this->administrator($repository, $this->hasher(), $this->auditLoggerExpectingNothing(), mcpConnections: $mcpConnections)
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
        ?AccountMcpConnectionsRevokerInterface $mcpConnections = null,
        ?AccountLockInterface $accountLock = null,
        ?DeadlockRetryInterface $deadlockRetry = null,
    ): CpgUserAdministrator {
        if (null === $accountLock) {
            $accountLock = self::createStub(AccountLockInterface::class);
            $accountLock->method('whileLocked')->willReturnCallback(
                static fn (CpgUser $user, Closure $operation): mixed => $operation($user),
            );
        }
        if (null === $mcpConnections) {
            $mcpConnections = self::createStub(AccountMcpConnectionsRevokerInterface::class);
            $mcpConnections->method('revokeAllOf')->willReturn(new RevokedAccountMcpConnections(AccountAccessWithdrawal::PasswordChanged, []));
        }
        if (null === $administrationLock) {
            $administrationLock = self::createStub(AccountAdministrationLockInterface::class);
            $administrationLock->method('whileAdministering')->willReturnCallback(
                static fn (CpgUser $target, CpgUser $actingUser, Closure $operation): mixed => $operation($target),
            );
        }

        return new CpgUserAdministrator($repository, $hasher, $auditLogger, $tokenRepository ?? self::createStub(PasswordSetupTokenRepositoryInterface::class), $accountLock, $administrationLock, $mcpConnections, $deadlockRetry ?? new DoctrineDeadlockRetry(self::createStub(ManagerRegistry::class), new NullLogger()));
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
