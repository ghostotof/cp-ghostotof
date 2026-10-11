<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Infrastructure\Messenger;

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\User\Application\CpgUserInviterInterface;
use App\Security\User\Application\Message\SendAccountInvitationMessage;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Security\User\Infrastructure\Messenger\SendAccountInvitationHandler;
use App\Tests\Support\ChildPhpProcess;
use App\Tests\Support\OpensProbeConnection;
use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

/**
 * Deux consommateurs sur le même compte ne laissent qu'un jeton valable
 * (issue #435).
 *
 * En `READ COMMITTED`, le `DELETE … WHERE user_id` d'un consommateur ne voit
 * pas l'`INSERT` non commité de l'autre : une fois les deux commits passés,
 * deux jetons étaient utilisables. L'entrelacement est explicite, sur le
 * modèle de ContributionOrderConcurrencyTest :
 *
 *  1. B (Fixtures/run-invitation-handler.php) démarre et attend la barrière ;
 *  2. A, dans ce processus, traite le message ; au `postFlush` de son jeton —
 *     inséré, pas encore commité —, le test relâche la barrière ;
 *  3. le test attend que B ait **fini** ou que sa session soit **bloquée sur
 *     un verrou** PostgreSQL, puis laisse A commiter ;
 *  4. une fois B terminé, le test compte les jetons du compte.
 *
 * Sans verrou par compte, B purge sans voir le jeton de A et commite le sien
 * pendant la pause : deux jetons. Avec, B attend A, puis remplace son jeton.
 */
final class SendAccountInvitationConcurrencyTest extends KernelTestCase
{
    use OpensProbeConnection;

    private const int TIMEOUT_SECONDS = 30;

    private const string HANDLER_SCRIPT = __DIR__.'/../../Fixtures/run-invitation-handler.php';

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

    public function testTwoConsumersOfTheSameAccountLeaveASingleUsableToken(): void
    {
        $container = self::getContainer();
        $container->get(CpgUserInviterInterface::class)->invite('newcomer@example.com', Locale::FR);
        $container->get(EntityManagerInterface::class)->clear();
        $user = $container->get(CpgUserRepositoryInterface::class)->findOneByEmail('newcomer@example.com');
        self::assertInstanceOf(CpgUser::class, $user);
        $userId = $user->getId()->toRfc4122();
        $generation = $user->getInvitationGeneration();
        $container->get(EntityManagerInterface::class)->clear();

        $concurrent = $this->interleaveAfterNextFlush($userId, $generation);
        $container->get(SendAccountInvitationHandler::class)(new SendAccountInvitationMessage($userId, 'fr', $generation));
        $concurrent->wait();
        self::assertSame(0, $concurrent->getExitCode(), $concurrent->getOutput().$concurrent->getErrorOutput());

        self::assertSame(1, $this->tokenCountOf($userId), 'Deux consommateurs du même compte ont laissé deux jetons utilisables (#435).');
    }

    /**
     * Démarre B, attend qu'il soit prêt, et arme un `postFlush` à usage unique
     * qui le lâche puis attend qu'il ait fini ou soit bloqué.
     */
    private function interleaveAfterNextFlush(string $userId, int $generation): Process
    {
        $barrierPath = tempnam(sys_get_temp_dir(), 'invitation-barrier-');
        self::assertIsString($barrierPath);
        $barrier = fopen($barrierPath, 'r');
        self::assertNotFalse($barrier);
        self::assertTrue(flock($barrier, \LOCK_EX));

        $concurrent = new Process(
            ChildPhpProcess::command(self::HANDLER_SCRIPT, $barrierPath, $userId, (string) $generation),
            env: $this->childEnvironment(),
            timeout: self::TIMEOUT_SECONDS,
        );
        $concurrent->start();
        $concurrentPid = $this->waitUntilReady($concurrent);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $probe = self::openProbeBeside($entityManager->getConnection());

        $listener = new class($barrier, $barrierPath, $concurrent, $concurrentPid, $probe, $this->waitUntilDoneOrBlocked(...)) {
            public bool $fired = false;

            /**
             * @param resource                                $barrier
             * @param Closure(Process, int, Connection): void $waitUntilDoneOrBlocked
             */
            public function __construct(
                private $barrier,
                private readonly string $barrierPath,
                private readonly Process $concurrent,
                private readonly int $concurrentPid,
                private readonly Connection $probe,
                private readonly Closure $waitUntilDoneOrBlocked,
            ) {
            }

            public function postFlush(PostFlushEventArgs $args): void
            {
                if ($this->fired) {
                    return;
                }
                $this->fired = true;

                flock($this->barrier, \LOCK_UN);
                fclose($this->barrier);
                unlink($this->barrierPath);
                ($this->waitUntilDoneOrBlocked)($this->concurrent, $this->concurrentPid, $this->probe);
                $this->probe->close();
            }
        };
        $entityManager->getEventManager()->addEventListener([Events::postFlush], $listener);

        return $concurrent;
    }

    /**
     * Rend la main dès que B a terminé ou que **sa** session attend un verrou
     * PostgreSQL, lu depuis une connexion à part, hors transaction.
     */
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
                self::fail('Le consommateur concurrent ni fini ni bloqué après '.self::TIMEOUT_SECONDS.' s.');
            }
            usleep(10_000);
        }
    }

    private function waitUntilReady(Process $concurrent): int
    {
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;

        while (true) {
            if (1 === preg_match('/^ready (\d+)$/m', $concurrent->getOutput(), $matches)) {
                return (int) $matches[1];
            }
            if (!$concurrent->isRunning()) {
                self::fail(\sprintf("Le consommateur concurrent s'est arrêté avant la barrière :\n%s", $concurrent->getErrorOutput()));
            }
            if (microtime(true) > $deadline) {
                self::fail("Le consommateur concurrent n'est pas prêt après ".self::TIMEOUT_SECONDS.' s.');
            }
            usleep(10_000);
        }
    }

    /**
     * @return array<string, string>
     */
    private function childEnvironment(): array
    {
        $environment = [];
        foreach ($_SERVER as $name => $value) {
            if (\is_string($name) && \is_string($value)) {
                $environment[$name] = $value;
            }
        }

        return $environment;
    }

    private function tokenCountOf(string $userId): int
    {
        $count = self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM password_setup_token WHERE user_id = :user',
            ['user' => $userId],
        );
        self::assertIsInt($count);

        return $count;
    }

    private function purge(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('DELETE FROM password_setup_token');
        $connection->executeStatement('DELETE FROM cpg_user');
    }
}
