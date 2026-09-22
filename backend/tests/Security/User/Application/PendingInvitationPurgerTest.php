<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Application;

use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\User\Application\PendingInvitationPurger;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\InvalidPurgeRetentionException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;

/**
 * Cas d'usage "purger les invitations jamais activées" (issue #238, RGPD) :
 * horloge figée pour que le seuil transmis au dépôt soit vérifiable à
 * l'assertion près, mocks pour épingler qui est réellement supprimé /
 * journalisé selon le rôle du compte et le mode dry-run.
 */
final class PendingInvitationPurgerTest extends TestCase
{
    private const string NOW = '2026-09-22 10:00:00';

    public function testPurgeRemovesEveryPendingAccountAndLogsEach(): void
    {
        $clock = new MockClock(self::NOW);
        $first = $this->pendingUser('first-invitee');
        $second = $this->pendingUser('second-invitee');

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findAwaitingPasswordSetupInvitedBefore')
            ->with(new \DateTimeImmutable('2026-08-23 10:00:00', new \DateTimeZone('UTC')))
            ->willReturn([$first, $second]);
        $repository->expects(self::exactly(2))->method('remove')
            ->with(self::logicalOr($first, $second));

        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::exactly(2))->method('userPurged')
            ->with(self::logicalOr($first, $second));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $purger = new PendingInvitationPurger($repository, $clock, $auditLogger, $logger);

        $result = $purger->purge(new \DateInterval('P30D'));

        self::assertSame(['first-invitee', 'second-invitee'], $result->purged);
        self::assertSame([], $result->skipped);
        self::assertEquals(new \DateTimeImmutable('2026-08-23 10:00:00', new \DateTimeZone('UTC')), $result->threshold);
    }

    public function testDryRunNeitherRemovesNorLogsButStillReportsWhatWouldBePurged(): void
    {
        $clock = new MockClock(self::NOW);
        $stale = $this->pendingUser('stale-invitee');

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->method('findAwaitingPasswordSetupInvitedBefore')->willReturn([$stale]);
        $repository->expects(self::never())->method('remove');

        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('userPurged');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $purger = new PendingInvitationPurger($repository, $clock, $auditLogger, $logger);

        $result = $purger->purge(new \DateInterval('P30D'), dryRun: true);

        self::assertSame(['stale-invitee'], $result->purged);
        self::assertSame([], $result->skipped);
    }

    public function testARoleSuperAccountIsNeverRemovedOnlySkippedAndWarnedAbout(): void
    {
        $clock = new MockClock(self::NOW);
        $superInvitee = $this->pendingUser('super-invitee');
        $superInvitee->setRoles([CpgUser::ROLE_SUPER]);

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->method('findAwaitingPasswordSetupInvitedBefore')->willReturn([$superInvitee]);
        $repository->expects(self::never())->method('remove');

        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('userPurged');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            self::anything(),
            self::callback(static fn (array $context): bool => 'super-invitee' === ($context['username'] ?? null)),
        );

        $purger = new PendingInvitationPurger($repository, $clock, $auditLogger, $logger);

        $result = $purger->purge(new \DateInterval('P30D'));

        self::assertSame(['super-invitee'], $result->skipped);
        self::assertSame([], $result->purged);
    }

    public function testAnEmptyRepositoryResultProducesAnEmptyResultAndNoWrite(): void
    {
        $clock = new MockClock(self::NOW);

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->method('findAwaitingPasswordSetupInvitedBefore')->willReturn([]);
        $repository->expects(self::never())->method('remove');

        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('userPurged');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $purger = new PendingInvitationPurger($repository, $clock, $auditLogger, $logger);

        $result = $purger->purge(new \DateInterval('P30D'));

        self::assertSame([], $result->purged);
        self::assertSame([], $result->skipped);
    }

    /**
     * I4 (défense en profondeur) : même si le dépôt renvoyait un jour un
     * compte qui ne devrait plus l'être (réécriture bâclée du SQL, régression
     * de findAwaitingPasswordSetupInvitedBefore()), le purgeur revérifie le
     * prédicat sur l'entité reçue et ne le supprime ni ne le journalise
     * jamais.
     */
    public function testAnAccountReturnedByTheRepositoryButNotActuallyAwaitingPasswordSetupIsNeverRemoved(): void
    {
        $clock = new MockClock(self::NOW);

        $passwordAlreadySet = new CpgUser('password-already-set', 'a-real-hash');
        $passwordAlreadySet->markInvited(new \DateTimeImmutable('-40 days'));

        $alreadyActivated = new CpgUser('already-activated', 'a-real-hash');
        $alreadyActivated->markInvited(new \DateTimeImmutable('-40 days'));
        $alreadyActivated->markActivated(new \DateTimeImmutable('-5 days'));

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->method('findAwaitingPasswordSetupInvitedBefore')
            ->willReturn([$passwordAlreadySet, $alreadyActivated]);
        $repository->expects(self::never())->method('remove');

        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('userPurged');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $purger = new PendingInvitationPurger($repository, $clock, $auditLogger, $logger);

        $result = $purger->purge(new \DateInterval('P30D'));

        self::assertSame([], $result->purged);
        self::assertSame([], $result->skipped);
    }

    /**
     * M6 (plancher de rétention) : "1 day" est la plus courte durée acceptée
     * — le seuil calculé (maintenant − 1 jour) reste strictement antérieur à
     * maintenant, donc valide, même si rien n'est trouvé à purger ici.
     */
    public function testARetentionOfExactlyOneDayIsAcceptedTheMinimumAllowed(): void
    {
        $clock = new MockClock(self::NOW);

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::once())->method('findAwaitingPasswordSetupInvitedBefore')->willReturn([]);

        $auditLogger = self::createStub(SecurityAuditLoggerInterface::class);
        $logger = self::createStub(LoggerInterface::class);

        $purger = new PendingInvitationPurger($repository, $clock, $auditLogger, $logger);

        $result = $purger->purge(new \DateInterval('P1D'));

        self::assertSame([], $result->purged);
    }

    /**
     * M6 : en dessous d'un jour ("1 hour"), la durée de rétention est refusée
     * — un lancement manuel imprudent ne doit pas pouvoir purger la quasi-
     * totalité des comptes en attente d'un coup.
     */
    public function testARetentionShorterThanOneDayIsRejectedBeforeAnyRepositoryAccess(): void
    {
        $clock = new MockClock(self::NOW);

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::never())->method('findAwaitingPasswordSetupInvitedBefore');
        $repository->expects(self::never())->method('remove');

        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('userPurged');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $purger = new PendingInvitationPurger($repository, $clock, $auditLogger, $logger);

        $this->expectException(InvalidPurgeRetentionException::class);

        $purger->purge(new \DateInterval('PT1H'));
    }

    public function testANegativeIntervalIsRejectedBeforeAnyRepositoryAccess(): void
    {
        $clock = new MockClock(self::NOW);

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::never())->method('findAwaitingPasswordSetupInvitedBefore');
        $repository->expects(self::never())->method('remove');

        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('userPurged');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $purger = new PendingInvitationPurger($repository, $clock, $auditLogger, $logger);

        // Double négation possible côté appelant ("--older-than=-30 days") :
        // \DateInterval::createFromDateString('-30 days') produit un
        // intervalle dont \DateTimeImmutable::sub() avance l'horloge au lieu
        // de la reculer — le seuil se retrouve dans le futur.
        $this->expectException(InvalidPurgeRetentionException::class);

        $purger->purge(\DateInterval::createFromDateString('-30 days'));
    }

    public function testAZeroLengthIntervalIsRejectedBeforeAnyRepositoryAccess(): void
    {
        $clock = new MockClock(self::NOW);

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::never())->method('findAwaitingPasswordSetupInvitedBefore');
        $repository->expects(self::never())->method('remove');

        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('userPurged');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $purger = new PendingInvitationPurger($repository, $clock, $auditLogger, $logger);

        // Un intervalle nul place le seuil exactement sur "maintenant" :
        // rejeté au même titre qu'un intervalle négatif.
        $this->expectException(InvalidPurgeRetentionException::class);

        $purger->purge(new \DateInterval('PT0S'));
    }

    private function pendingUser(string $username): CpgUser
    {
        $user = new CpgUser($username, '');
        $user->markInvited(new \DateTimeImmutable('-40 days'));

        return $user;
    }
}
