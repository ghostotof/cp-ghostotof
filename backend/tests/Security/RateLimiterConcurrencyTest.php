<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Des consume() simultanés sur la même clé décomptent chacun leur unité
 * (issue #277, suite de #272).
 *
 * RateLimiterStorageTest pince le *câblage* : chaque limiteur reçoit un verrou
 * advisory PostgreSQL. Il ne verrait pas une régression de *comportement* —
 * une montée de symfony/rate-limiter qui cesserait d'appeler acquire(true), un
 * store devenu non bloquant. Ce test-ci exerce le comportement : sans verrou,
 * consume() fait un lire-modifier-écrire non atomique sur cache.app, et des
 * requêtes simultanées se partagent une seule unité de quota.
 *
 * Le test ne peut pas consommer lui-même : il tiendrait le verrou que ses
 * consommateurs attendent. Il lance donc SIMULTANEOUS_CONSUMERS processus
 * (Fixtures/consume-rate-limiter.php), qui démarrent chacun le kernel, puis
 * se bloquent sur un verrou partagé d'un fichier-barrière que le test tient
 * en exclusif. Une fois tous prêts, le test relâche la barrière : ils
 * consomment au même instant. Barrière par fichier plutôt que par horloge :
 * un démarrage lent en CI retarde la libération au lieu d'étaler les
 * consommations, ce qui rendrait le test vert sans rien prouver. Pour la même
 * raison, chaque fils ouvre ses connexions PostgreSQL avant la barrière.
 *
 * Ce qui peut encore affaiblir la course : un fils écrit « ready » juste avant
 * de se bloquer sur la barrière, et pourrait l'atteindre après sa libération.
 * RELEASE_DELAY_MICROSECONDS couvre cet intervalle. Le seul effet possible est
 * un test moins sensible, jamais un faux rouge : avec le verrou, l'ordre
 * d'arrivée ne change pas le décompte.
 *
 * Rouge vérifié le 2026-10-10 avec `lock_factory: null` sur chaque limiteur
 * exercé : le décompte perd des unités à chaque passage.
 */
final class RateLimiterConcurrencyTest extends KernelTestCase
{
    /**
     * Assez pour qu'une course sans verrou soit quasi certaine, assez peu
     * pour rester sous la plus petite limite exercée (25) et sous
     * max_connections (deux connexions PostgreSQL par processus).
     */
    private const int SIMULTANEOUS_CONSUMERS = 10;

    /** Borne de chaque attente : un verrou jamais relâché fait échouer le test au lieu de le suspendre. */
    private const int TIMEOUT_SECONDS = 60;

    /** Laisse à chaque fils, après son « ready », le temps d'atteindre son flock(). */
    private const int RELEASE_DELAY_MICROSECONDS = 100_000;

    private const string CONSUMER_SCRIPT = __DIR__.'/Fixtures/consume-rate-limiter.php';

    /**
     * Un limiteur par politique présente dans le conteneur : chaque politique
     * prend le verrou dans son propre consume().
     *
     * @return iterable<string, array{0: string}>
     */
    public static function provideLimiterNames(): iterable
    {
        yield 'sliding_window (translation_assistant)' => ['translation_assistant'];
        // login_throttling impose fixed_window ; le limiteur par IP, de
        // limite 5 × max_attempts, laisse la place aux consommateurs.
        yield 'fixed_window (login throttling, global)' => ['_login_global_login'];
    }

    #[DataProvider('provideLimiterNames')]
    public function testSimultaneousConsumptionsOfTheSameKeyAreAllCounted(string $limiterName): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get('limiter.'.$limiterName);
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $factory);

        // Clé propre à chaque passage : aucun état hérité d'un passage précédent.
        $key = 'concurrency-test-'.bin2hex(random_bytes(8));
        $limiter = $factory->create($key);
        $barrierPath = $this->createBarrier();
        $barrier = fopen($barrierPath, 'r');
        self::assertNotFalse($barrier);

        $consumers = [];

        try {
            $before = $limiter->consume(0)->getRemainingTokens();
            self::assertGreaterThanOrEqual(
                self::SIMULTANEOUS_CONSUMERS,
                $before,
                \sprintf('La limite de limiter.%s est passée sous SIMULTANEOUS_CONSUMERS : baisser ce dernier.', $limiterName),
            );

            self::assertTrue(flock($barrier, \LOCK_EX));
            for ($i = 0; $i < self::SIMULTANEOUS_CONSUMERS; ++$i) {
                $consumers[] = $this->startConsumer($limiterName, $key, $barrierPath);
            }
            $this->waitUntilAllReady($consumers);
            usleep(self::RELEASE_DELAY_MICROSECONDS);
            flock($barrier, \LOCK_UN);

            $remainingSeen = $this->collectRemainingTokensSeen($consumers);
            $after = $limiter->consume(0)->getRemainingTokens();

            self::assertSame(
                $before - self::SIMULTANEOUS_CONSUMERS,
                $after,
                \sprintf(
                    '%d consume(1) simultanés sur limiter.%s ont décompté %d unité(s) : le verrou ne sérialise plus les écritures (#272). Restes vus par les consommateurs : %s.',
                    self::SIMULTANEOUS_CONSUMERS,
                    $limiterName,
                    $before - $after,
                    implode(', ', $remainingSeen),
                ),
            );
        } finally {
            foreach ($consumers as $consumer) {
                $consumer->stop(0);
            }
            fclose($barrier);
            unlink($barrierPath);
            $this->forgetKey($limiter);
        }
    }

    /**
     * Efface la fenêtre de la clé. reset() prend le verrou et écrit en base :
     * s'il échoue (verrou jamais relâché, connexion perdue), il ne doit pas
     * remplacer l'échec ou le timeout qui l'explique. La clé est aléatoire, une
     * ligne oubliée n'affecte aucun autre test.
     */
    private function forgetKey(LimiterInterface $limiter): void
    {
        try {
            $limiter->reset();
        } catch (\Throwable) {
        }
    }

    private function createBarrier(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rate-limiter-barrier-');
        self::assertIsString($path);

        return $path;
    }

    private function startConsumer(string $limiterName, string $key, string $barrierPath): Process
    {
        $process = new Process(
            [\PHP_BINARY, self::CONSUMER_SCRIPT, $limiterName, $key, $barrierPath],
            env: $this->consumerEnvironment(),
            timeout: self::TIMEOUT_SECONDS,
        );
        $process->start();

        return $process;
    }

    /**
     * Les variables que phpunit.dist.xml force par `<server force="true">`
     * (APP_ENV, clés d'API factices…) ne vivent que dans $_SERVER : un
     * processus fils ne les hérite pas. Sans elles, Dotenv chargerait le .env
     * de dev, et une vraie ANTHROPIC_API_KEY exportée dans le shell parviendrait
     * au fils à la place de la valeur factice, ce qui contournerait le filet de
     * phpunit.dist.xml. Les transmettre explicitement donne au fils
     * l'environnement exact du test.
     *
     * @return array<string, string>
     */
    private function consumerEnvironment(): array
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
     * @param list<Process> $consumers
     */
    private function waitUntilAllReady(array $consumers): void
    {
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;

        foreach ($consumers as $consumer) {
            while (!str_contains($consumer->getOutput(), "ready\n")) {
                if (!$consumer->isRunning()) {
                    self::fail(\sprintf("Un consommateur s'est arrêté avant la barrière :\n%s", $consumer->getErrorOutput()));
                }
                if (microtime(true) > $deadline) {
                    self::fail('Les consommateurs ne sont pas tous prêts après '.self::TIMEOUT_SECONDS.' s.');
                }
                usleep(10_000);
            }
        }
    }

    /**
     * Attend chaque consommateur et rend le nombre d'unités restantes qu'il a vu.
     *
     * @param list<Process> $consumers
     *
     * @return list<string>
     */
    private function collectRemainingTokensSeen(array $consumers): array
    {
        $remainingSeen = [];
        foreach ($consumers as $consumer) {
            $consumer->wait();
            self::assertSame(
                0,
                $consumer->getExitCode(),
                \sprintf("Un consommateur a échoué ou s'est vu refuser son unité :\n%s%s", $consumer->getOutput(), $consumer->getErrorOutput()),
            );
            $lines = explode("\n", trim($consumer->getOutput()));
            $remainingSeen[] = end($lines);
        }

        return $remainingSeen;
    }
}
