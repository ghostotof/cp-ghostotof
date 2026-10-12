<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Application;

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\User\Application\CpgUserInviterInterface;
use App\Tests\Support\ChildPhpProcess;
use App\Tests\Support\OpensProbeConnection;
use App\Tests\Support\ProcessBarrier;
use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

/**
 * Deux invitations simultanées de même partie locale reçoivent deux
 * identifiants distincts (issue #364).
 *
 * `jean@a.example` et `jean@b.example` dérivent toutes deux `jean`.
 * `UsernameGenerator` vérifie que l'identifiant est libre, mais cette lecture
 * n'était pas atomique avec l'écriture : chacune voyait `jean` libre, la
 * seconde échouait sur la contrainte unique de l'identifiant, et le backoffice
 * affichait « adresse déjà utilisée » pour une adresse que personne n'avait.
 * L'entrelacement, sur le modèle de PasswordSetupActivationConcurrencyTest :
 *
 *  1. B (Fixtures/invite-account.php) attend la barrière ;
 *  2. A, dans ce processus, invite `jean@a.example` ; au `preFlush` de son
 *     enregistrement — identifiant choisi, ligne pas encore écrite —, le test
 *     relâche B, qui invite `jean@b.example` ;
 *  3. le test attend que B ait **fini** ou soit **bloqué sur un verrou** ;
 *  4. A termine, puis B.
 *
 * Les deux invitations aboutissent : B attend que A ait validé, puis voit
 * `jean` pris et prend `jean2`, comme deux invitations successives.
 */
final class InviteUsernameConcurrencyTest extends KernelTestCase
{
    use OpensProbeConnection;

    private const int TIMEOUT_SECONDS = 30;

    private const string INVITE_SCRIPT = __DIR__.'/../Fixtures/invite-account.php';

    protected function setUp(): void
    {
        self::bootKernel();
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    public function testTwoConcurrentInvitationsOfTheSameLocalPartBothSucceedWithDistinctUsernames(): void
    {
        $concurrent = $this->interleaveBeforeNextFlush('jean@b.example');

        $user = self::getContainer()->get(CpgUserInviterInterface::class)->invite('jean@a.example', Locale::FR);
        $concurrent->wait();

        self::assertSame('jean', $user->getUsername());
        self::assertSame(
            0,
            $concurrent->getExitCode(),
            'L\'invitation concurrente a été refusée (#364) : '.$concurrent->getOutput().$concurrent->getErrorOutput(),
        );
        self::assertSame(1, preg_match('/^username (\S+)$/m', $concurrent->getOutput(), $matches), $concurrent->getOutput());
        self::assertSame('jean2', $matches[1]);
        self::assertSame(
            ['jean@a.example' => 'jean', 'jean@b.example' => 'jean2'],
            $this->storedUsernamesByEmail(),
        );
    }

    private function interleaveBeforeNextFlush(string $concurrentEmail): Process
    {
        $barrier = ProcessBarrier::hold('invite-barrier-');
        $concurrent = ChildPhpProcess::inTestEnvironment(self::TIMEOUT_SECONDS, self::INVITE_SCRIPT, $barrier->path(), $concurrentEmail);
        $concurrent->start();
        $concurrentPid = $barrier->sessionPidOf($concurrent, self::TIMEOUT_SECONDS);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $probe = self::openProbeBeside($entityManager->getConnection());

        $listener = new class($barrier, $concurrent, $concurrentPid, $probe, $this->waitUntilDoneOrBlocked(...)) {
            public bool $fired = false;

            /**
             * @param Closure(Process, int, Connection): void $waitUntilDoneOrBlocked
             */
            public function __construct(
                private readonly ProcessBarrier $barrier,
                private readonly Process $concurrent,
                private readonly int $concurrentPid,
                private readonly Connection $probe,
                private readonly Closure $waitUntilDoneOrBlocked,
            ) {
            }

            public function preFlush(PreFlushEventArgs $args): void
            {
                if ($this->fired) {
                    return;
                }
                $this->fired = true;

                $this->barrier->release();
                ($this->waitUntilDoneOrBlocked)($this->concurrent, $this->concurrentPid, $this->probe);
                $this->probe->close();
            }
        };
        $entityManager->getEventManager()->addEventListener([Events::preFlush], $listener);

        return $concurrent;
    }

    private function waitUntilDoneOrBlocked(Process $concurrent, int $concurrentPid, Connection $probe): void
    {
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;

        while ($concurrent->isRunning()) {
            $blocked = $probe->fetchOne(
                "SELECT wait_event_type IS NOT DISTINCT FROM 'Lock' FROM pg_stat_activity WHERE pid = ?",
                [$concurrentPid],
            );
            if (true === $blocked) {
                return;
            }
            if (microtime(true) > $deadline) {
                self::fail('L\'invitation concurrente ni finie ni bloquée après '.self::TIMEOUT_SECONDS.' s.');
            }
            usleep(10_000);
        }
    }

    /**
     * @return array<string, string>
     */
    private function storedUsernamesByEmail(): array
    {
        $rows = self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchAllKeyValue(
            'SELECT email, username FROM cpg_user ORDER BY email',
        );

        $usernames = [];
        foreach ($rows as $email => $username) {
            self::assertIsString($username);
            $usernames[(string) $email] = $username;
        }

        return $usernames;
    }

    private function purge(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('DELETE FROM password_setup_token');
        $connection->executeStatement('DELETE FROM cpg_user');
    }
}
