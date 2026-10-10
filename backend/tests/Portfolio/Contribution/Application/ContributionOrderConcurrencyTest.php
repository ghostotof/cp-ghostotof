<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Contribution\Application;

use App\Portfolio\Contribution\Application\ContributionAdministratorInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Tests\Support\OpensProbeConnection;
use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

/**
 * Une écriture du périmètre d'ordre ne peut pas en défaire une autre (issue #389).
 *
 * Deux écritures concurrentes, A dans ce processus, B dans un processus fils
 * (Fixtures/create-contribution.php) : l'une tiendrait sinon le verrou que
 * l'autre attend. L'entrelacement est explicite, pas laissé au hasard :
 *
 *  1. B démarre le kernel puis attend la barrière (fichier verrouillé en
 *     exclusif par le test) ;
 *  2. A lit le périmètre et calcule ses positions ; au `preFlush` de son
 *     écriture — le dernier instant avant qu'elle n'atteigne la base —, le
 *     test relâche la barrière ;
 *  3. le test attend que B ait **fini** ou soit **bloqué sur un verrou**
 *     PostgreSQL, puis laisse A écrire ;
 *  4. une fois B terminé, le test relit la table.
 *
 * Sans verrou de périmètre, B lit l'état antérieur à A et termine pendant la
 * pause : le résultat est celui de la course. Avec, B attend A et relit après
 * lui. Dans les deux cas le verdict est le même à chaque passage.
 */
final class ContributionOrderConcurrencyTest extends KernelTestCase
{
    use OpensProbeConnection;

    /** Borne de chaque attente : un verrou jamais relâché fait échouer le test au lieu de le suspendre. */
    private const int TIMEOUT_SECONDS = 30;

    private const string WRITER_SCRIPT = __DIR__.'/../Fixtures/create-contribution.php';

    protected function tearDown(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('DELETE FROM contribution');
        parent::tearDown();
    }

    /**
     * Le scénario de l'issue : « Créer la version EN » de G pendant un
     * `reorder()` qui déplace G. La version EN doit hériter de la position
     * que le réordonnancement donne à G, pas de celle qu'il quitte.
     */
    public function testACreationInAGroupDuringAReorderInheritsTheGroupsNewPosition(): void
    {
        self::bootKernel();
        $administrator = $this->administrator();
        $moved = $administrator->create(Locale::FR, 'Déplacée', 'P', 'R', 'https://example.com/a', 'S', 'B');
        $other = $administrator->create(Locale::FR, 'Autre', 'P', 'R', 'https://example.com/b', 'S', 'B');
        $group = $moved->getTranslationGroup();

        $writer = $this->interleaveBeforeNextFlush(Locale::EN, $group->toRfc4122());
        $administrator->reorder([$other->getTranslationGroup()->toRfc4122(), $group->toRfc4122()]);
        $this->awaitSuccess($writer);

        self::assertSame(
            [1, 1],
            $this->positionsOf($group->toRfc4122()),
            'Le groupe déplacé porte deux positions : la version créée pendant le réordonnancement a hérité de la position que le groupe quittait (#389).',
        );
    }

    /**
     * La course voisine que l'issue demandait de confirmer : deux créations
     * sans groupe lisent le même `max`, et `atEndOf()` leur donne la même
     * position — deux clés sur une position.
     */
    public function testTwoSimultaneousCreationsWithoutAGroupGetDistinctPositions(): void
    {
        self::bootKernel();
        $administrator = $this->administrator();
        $administrator->create(Locale::FR, 'Existante', 'P', 'R', 'https://example.com/a', 'S', 'B');

        $writer = $this->interleaveBeforeNextFlush(Locale::FR, null);
        $administrator->create(Locale::FR, 'Créée par A', 'P', 'R', 'https://example.com/c', 'S', 'B');
        $this->awaitSuccess($writer);

        self::assertSame(
            [0, 1, 2],
            $this->allPositions(),
            'Deux créations simultanées sans groupe ont reçu la même position de fin de périmètre (#389).',
        );
    }

    private function administrator(): ContributionAdministratorInterface
    {
        return self::getContainer()->get(ContributionAdministratorInterface::class);
    }

    /**
     * Démarre B, attend qu'il soit prêt, et arme un `preFlush` à usage unique
     * qui le lâche puis attend qu'il ait fini ou soit bloqué.
     */
    private function interleaveBeforeNextFlush(Locale $locale, ?string $group): Process
    {
        $barrierPath = tempnam(sys_get_temp_dir(), 'order-barrier-');
        self::assertIsString($barrierPath);
        $barrier = fopen($barrierPath, 'r');
        self::assertNotFalse($barrier);
        self::assertTrue(flock($barrier, \LOCK_EX));

        $command = [\PHP_BINARY, self::WRITER_SCRIPT, $barrierPath, $locale->value];
        if (null !== $group) {
            $command[] = $group;
        }

        $writer = new Process(
            $command,
            env: $this->childEnvironment(),
            timeout: self::TIMEOUT_SECONDS,
        );
        $writer->start();
        $this->waitUntilReady($writer);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $probe = self::openProbeBeside($entityManager->getConnection());
        $eventManager = $entityManager->getEventManager();

        $listener = new class($barrier, $barrierPath, $writer, $probe, $this->waitUntilDoneOrBlocked(...)) {
            /**
             * @param resource                          $barrier
             * @param Closure(Process, Connection): void $waitUntilDoneOrBlocked
             */
            public function __construct(
                private $barrier,
                private readonly string $barrierPath,
                private readonly Process $writer,
                private readonly Connection $probe,
                private readonly Closure $waitUntilDoneOrBlocked,
            ) {
            }

            public bool $fired = false;

            public function preFlush(PreFlushEventArgs $args): void
            {
                if ($this->fired) {
                    return;
                }
                $this->fired = true;

                flock($this->barrier, \LOCK_UN);
                fclose($this->barrier);
                unlink($this->barrierPath);
                ($this->waitUntilDoneOrBlocked)($this->writer, $this->probe);
                $this->probe->close();
            }
        };
        $eventManager->addEventListener([Events::preFlush], $listener);

        return $writer;
    }

    /**
     * Rend la main dès que B a terminé ou attend un verrou PostgreSQL. Interrogé
     * depuis une connexion à part, hors de toute transaction : pg_stat_activity
     * est figé pour la durée d'une transaction.
     */
    private function waitUntilDoneOrBlocked(Process $writer, Connection $probe): void
    {
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;

        while ($writer->isRunning()) {
            $blocked = $probe->fetchOne(
                "SELECT count(*) > 0 FROM pg_stat_activity WHERE datname = current_database() AND wait_event_type = 'Lock' AND pid <> pg_backend_pid()",
            );
            if (true === $blocked) {
                return;
            }
            if (microtime(true) > $deadline) {
                self::fail('Le processus concurrent ni fini ni bloqué après '.self::TIMEOUT_SECONDS.' s.');
            }
            usleep(10_000);
        }
    }

    private function waitUntilReady(Process $writer): void
    {
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;

        while (!str_contains($writer->getOutput(), "ready\n")) {
            if (!$writer->isRunning()) {
                self::fail(\sprintf("Le processus concurrent s'est arrêté avant la barrière :\n%s", $writer->getErrorOutput()));
            }
            if (microtime(true) > $deadline) {
                self::fail("Le processus concurrent n'est pas prêt après ".self::TIMEOUT_SECONDS.' s.');
            }
            usleep(10_000);
        }
    }

    private function awaitSuccess(Process $writer): void
    {
        $writer->wait();
        self::assertSame(
            0,
            $writer->getExitCode(),
            \sprintf("Le processus concurrent a échoué :\n%s%s", $writer->getOutput(), $writer->getErrorOutput()),
        );
    }

    /**
     * Même transmission que RateLimiterConcurrencyTest : les variables que
     * phpunit.dist.xml force ne vivent que dans $_SERVER.
     *
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

    /**
     * @return list<int>
     */
    private function positionsOf(string $group): array
    {
        return $this->positions('SELECT position FROM contribution WHERE translation_group = ? ORDER BY locale', [$group]);
    }

    /**
     * @return list<int>
     */
    private function allPositions(): array
    {
        return $this->positions('SELECT position FROM contribution ORDER BY position');
    }

    /**
     * @param list<string> $parameters
     *
     * @return list<int>
     */
    private function positions(string $sql, array $parameters = []): array
    {
        $positions = [];
        foreach (self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchFirstColumn($sql, $parameters) as $position) {
            self::assertIsInt($position);
            $positions[] = $position;
        }

        return $positions;
    }
}
