<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Application;

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\User\Application\PasswordSetupServiceInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\PasswordSetupTokenExpiredException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Tests\Support\ChildPhpProcess;
use App\Tests\Support\InvitesUsers;
use App\Tests\Support\OpensProbeConnection;
use App\Tests\Support\TestCredentials;
use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

/**
 * Un compte activé ne garde aucun jeton utilisable (issue #458).
 *
 * L'activation (`PasswordSetupService::complete()`) ne prenait pas le verrou
 * du compte que pose le handler d'invitation (#435) : un handler qui passait
 * entre la réclamation du jeton et l'écriture de l'activation voyait le compte
 * encore en attente et émettait un nouveau jeton, valable 48 h sur un compte
 * actif — assez pour en redéfinir le mot de passe. L'entrelacement, sur le
 * modèle de SendAccountInvitationConcurrencyTest :
 *
 *  1. B (Fixtures/run-invitation-handler.php) attend la barrière ;
 *  2. A, dans ce processus, active le compte ; au `preFlush` de l'activation
 *     — jeton réclamé, activation pas encore écrite —, le test relâche B ;
 *  3. le test attend que B ait **fini** ou soit **bloqué sur un verrou** ;
 *  4. une fois B terminé, le test compte les jetons non utilisés du compte,
 *     puis rejoue le lien consommé.
 *
 * La purge des jetons non utilisés à l'activation suffit à n'en laisser
 * aucun. Le verrou garantit en plus que le handler attend au lieu de supprimer
 * le jeton déjà réclamé : sans lui, le rejeu du lien répondait 404 au lieu de
 * 410, et le journal perdait l'événement `replayed`.
 */
final class PasswordSetupActivationConcurrencyTest extends KernelTestCase
{
    use InvitesUsers;
    use OpensProbeConnection;

    private const int TIMEOUT_SECONDS = 30;

    private const string HANDLER_SCRIPT = __DIR__.'/../Fixtures/run-invitation-handler.php';

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

    public function testAnInvitationHandledDuringTheActivationLeavesNoUsableToken(): void
    {
        $clearToken = $this->inviteAndCollectSetupToken('newcomer@example.com', Locale::FR);
        $user = self::getContainer()->get(CpgUserRepositoryInterface::class)->findOneByEmail('newcomer@example.com');
        self::assertInstanceOf(CpgUser::class, $user);
        $userId = $user->getId()->toRfc4122();
        $generation = $user->getInvitationGeneration();
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $concurrent = $this->interleaveBeforeNextFlush($userId, $generation);
        self::getContainer()->get(PasswordSetupServiceInterface::class)->complete($clearToken, TestCredentials::variant('activation'));
        $concurrent->wait();
        self::assertSame(0, $concurrent->getExitCode(), $concurrent->getOutput().$concurrent->getErrorOutput());

        self::assertTrue($this->isActivated($userId));
        self::assertSame(0, $this->unusedTokenCountOf($userId), 'Un compte activé garde un jeton utilisable (#458).');

        // Ce que seul le verrou garantit : le handler a attendu l'activation
        // au lieu de supprimer le jeton déjà réclamé. Le lien consommé reste
        // connu, et son rejeu est reconnu comme tel (410, `replayed`), pas pris
        // pour un lien inconnu (404).
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->expectException(PasswordSetupTokenExpiredException::class);
        self::getContainer()->get(PasswordSetupServiceInterface::class)->complete($clearToken, TestCredentials::variant('rejeu'));
    }

    private function interleaveBeforeNextFlush(string $userId, int $generation): Process
    {
        $barrierPath = tempnam(sys_get_temp_dir(), 'activation-barrier-');
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

            public function preFlush(PreFlushEventArgs $args): void
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
                self::fail('Le handler concurrent ni fini ni bloqué après '.self::TIMEOUT_SECONDS.' s.');
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
                self::fail(\sprintf("Le handler concurrent s'est arrêté avant la barrière :\n%s", $concurrent->getErrorOutput()));
            }
            if (microtime(true) > $deadline) {
                self::fail("Le handler concurrent n'est pas prêt après ".self::TIMEOUT_SECONDS.' s.');
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

    private function isActivated(string $userId): bool
    {
        return null !== self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne(
            'SELECT activated_at FROM cpg_user WHERE id = :id',
            ['id' => $userId],
        );
    }

    private function unusedTokenCountOf(string $userId): int
    {
        $count = self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM password_setup_token WHERE user_id = :user AND used_at IS NULL',
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
