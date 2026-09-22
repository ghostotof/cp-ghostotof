<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Presentation\Command;

use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Entity\PasswordSetupToken;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Security\User\Domain\Repository\PasswordSetupTokenRepositoryInterface;
use App\Tests\Support\ReadsSecurityAuditLog;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Couvre app:user:purge-pending-invitations (issue #238, RGPD) : seul un
 * compte en attente d'activation dont la dernière invitation dépasse le seuil
 * est supprimé (jeton compris), un compte ROLE_SUPER en attente reste une
 * décision humaine, le dry-run ne modifie rien, et un intervalle invalide
 * échoue proprement. Le journal de sécurité (`user-purged`) est vérifié à la
 * fois pour son contenu et pour l'absence de tout e-mail.
 */
final class PurgePendingInvitationsCommandTest extends KernelTestCase
{
    use ReadsSecurityAuditLog;

    private Connection $connection;
    private CpgUserRepositoryInterface $userRepository;
    private PasswordSetupTokenRepositoryInterface $tokenRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->userRepository = self::getContainer()->get(CpgUserRepositoryInterface::class);
        $this->tokenRepository = self::getContainer()->get(PasswordSetupTokenRepositoryInterface::class);

        $this->purgeUsers();
    }

    protected function tearDown(): void
    {
        $this->purgeUsers();
        parent::tearDown();
    }

    /** FK ON DELETE CASCADE : supprime aussi les jetons rattachés. */
    private function purgeUsers(): void
    {
        $this->connection->executeStatement('DELETE FROM cpg_user');
    }

    public function testStaleAccountIsPurgedItsTokenDisappearsAndAnAuditEventIsLoggedWithNoEmail(): void
    {
        $stale = new CpgUser('stale-invitee', '');
        $stale->setEmail('stale@example.com');
        $stale->markInvited(new \DateTimeImmutable('-40 days'));
        $this->userRepository->save($stale);
        $this->tokenRepository->save(new PasswordSetupToken(
            $stale,
            hash('sha256', 'clear-token-stale-invitee'),
            new \DateTimeImmutable('+48 hours'),
        ));

        $exitCode = $this->commandTester()->execute(['--older-than' => '30 days']);

        self::assertSame(0, $exitCode);
        self::assertNull($this->userRepository->findOneByUsername('stale-invitee'));
        self::assertSame(0, $this->countTokens());

        $event = self::singleSecurityAuditEvent('user-purged');
        self::assertSame('system', $event['actor']);
        self::assertSame('invitation-expired', $event['reason']);
        self::assertSame('stale-invitee', $event['user']);
        self::assertSame($stale->getId()->toRfc4122(), $event['userId']);

        foreach (self::securityAuditRecords() as $record) {
            $serialized = json_encode($record->context, \JSON_THROW_ON_ERROR);
            self::assertStringNotContainsStringIgnoringCase('stale@example.com', $serialized);
        }
    }

    public function testRecentAccountIsKept(): void
    {
        $recent = new CpgUser('recent-invitee', '');
        $recent->markInvited(new \DateTimeImmutable('-10 days'));
        $this->userRepository->save($recent);

        $exitCode = $this->commandTester()->execute(['--older-than' => '30 days']);

        self::assertSame(0, $exitCode);
        self::assertNotNull($this->userRepository->findOneByUsername('recent-invitee'));
        self::assertSame([], self::securityAuditEvents('user-purged'));
    }

    public function testActivatedAccountIsKept(): void
    {
        $activated = new CpgUser('activated-invitee', '');
        $activated->markInvited(new \DateTimeImmutable('-40 days'));
        $activated->markActivated(new \DateTimeImmutable('-5 days'));
        $this->userRepository->save($activated);

        $exitCode = $this->commandTester()->execute(['--older-than' => '30 days']);

        self::assertSame(0, $exitCode);
        self::assertNotNull($this->userRepository->findOneByUsername('activated-invitee'));
        self::assertSame([], self::securityAuditEvents('user-purged'));
    }

    public function testDryRunRemovesNothingAndListsTheAccountUnderASimulationTitle(): void
    {
        $stale = new CpgUser('dry-run-invitee', '');
        $stale->markInvited(new \DateTimeImmutable('-40 days'));
        $this->userRepository->save($stale);

        $tester = $this->commandTester();
        $exitCode = $tester->execute(['--older-than' => '30 days', '--dry-run' => true]);

        self::assertSame(0, $exitCode);
        self::assertNotNull($this->userRepository->findOneByUsername('dry-run-invitee'));
        self::assertStringContainsString('dry-run-invitee', $tester->getDisplay());
        self::assertStringContainsStringIgnoringCase('simulation', $tester->getDisplay());
        self::assertSame([], self::securityAuditEvents('user-purged'));
    }

    public function testPendingRoleSuperAccountIsKeptAndNamedAsSkippedInTheOutput(): void
    {
        $superInvitee = new CpgUser('super-invitee', '');
        $superInvitee->setRoles([CpgUser::ROLE_SUPER]);
        $superInvitee->markInvited(new \DateTimeImmutable('-40 days'));
        $this->userRepository->save($superInvitee);

        $tester = $this->commandTester();
        $exitCode = $tester->execute(['--older-than' => '30 days']);

        self::assertSame(0, $exitCode);
        self::assertNotNull($this->userRepository->findOneByUsername('super-invitee'));
        // Pas seulement présent quelque part dans la sortie : nommé après le
        // titre de la section "ignorés", jamais sous "Comptes purgés".
        $display = $tester->getDisplay();
        $ignoredSectionPosition = strpos($display, 'ignoré');
        $usernamePosition = strpos($display, 'super-invitee');
        self::assertNotFalse($ignoredSectionPosition, 'La section des comptes ignorés doit être affichée.');
        self::assertNotFalse($usernamePosition, 'Le compte doit être nommé dans la sortie.');
        self::assertGreaterThan($ignoredSectionPosition, $usernamePosition, 'Le compte doit être listé après le titre de la section "ignorés".');
        self::assertStringNotContainsString('Comptes purgés', $display);
        self::assertSame([], self::securityAuditEvents('user-purged'));
    }

    public function testFailsOnInvalidInterval(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute(['--older-than' => 'not-an-interval']);

        self::assertSame(2, $exitCode); // Command::INVALID
        self::assertStringContainsString('Intervalle invalide', $tester->getDisplay());
    }

    /**
     * Constat de la revue : "--older-than=-30 days" traverse la garde
     * syntaxique (new \DateTimeImmutable('--30 days') est acceptée par PHP)
     * mais \DateInterval::createFromDateString('-30 days') produit un
     * intervalle qui, une fois soustrait à "maintenant", avance l'horloge —
     * le seuil se retrouve dans le futur et purgerait tous les comptes en
     * attente (hors ROLE_SUPER). Doit être refusé avant toute suppression.
     */
    public function testNegativeOlderThanIsRejectedAndPurgesNothing(): void
    {
        $recentInvitee = new CpgUser('recent-guard-invitee', '');
        $recentInvitee->markInvited(new \DateTimeImmutable('-1 day'));
        $this->userRepository->save($recentInvitee);

        $tester = $this->commandTester();
        $exitCode = $tester->execute(['--older-than' => '-30 days']);

        self::assertSame(2, $exitCode); // Command::INVALID
        self::assertNotNull($this->userRepository->findOneByUsername('recent-guard-invitee'));
        self::assertSame([], self::securityAuditEvents('user-purged'));
    }

    /**
     * Même danger avec un intervalle nul : le seuil calculé serait
     * "maintenant" pile, ce qui n'est jamais une durée de rétention valide.
     */
    public function testZeroOlderThanIsRejectedAndPurgesNothing(): void
    {
        $recentInvitee = new CpgUser('zero-guard-invitee', '');
        $recentInvitee->markInvited(new \DateTimeImmutable('-1 day'));
        $this->userRepository->save($recentInvitee);

        $tester = $this->commandTester();
        $exitCode = $tester->execute(['--older-than' => '0 days']);

        self::assertSame(2, $exitCode); // Command::INVALID
        self::assertNotNull($this->userRepository->findOneByUsername('zero-guard-invitee'));
        self::assertSame([], self::securityAuditEvents('user-purged'));
    }

    private function countTokens(): int
    {
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM password_setup_token');
        \assert(is_numeric($count));

        return (int) $count;
    }

    private function commandTester(): CommandTester
    {
        \assert(self::$kernel instanceof KernelInterface);

        return new CommandTester((new Application(self::$kernel))->find('app:user:purge-pending-invitations'));
    }
}
