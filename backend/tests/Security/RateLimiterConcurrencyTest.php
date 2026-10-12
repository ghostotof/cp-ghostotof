<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Tests\Support\ChildPhpProcess;
use App\Tests\Support\ProcessBarrier;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Throwable;

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
 * se bloquent sur la barrière que le test tient (ProcessBarrier, dont le
 * docblock dit pourquoi un fichier plutôt qu'une horloge, et comment elle
 * couvre l'intervalle entre le « ready » d'un fils et son blocage). Une fois
 * tous prêts, le test relâche la barrière : ils consomment au même instant.
 * Chaque fils ouvre ses connexions PostgreSQL avant la barrière : leur durée,
 * variable d'un fils à l'autre, étalerait sinon les consommations. Avec le
 * verrou, l'ordre d'arrivée ne change pas le décompte.
 *
 * Rouge vérifié le 2026-10-10 avec `lock_factory: null` sur chaque limiteur
 * exercé : le décompte perd des unités à chaque passage. Revérifié le
 * 2026-10-12 après le passage au harnais commun (#403).
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
        $barrier = ProcessBarrier::hold('rate-limiter-barrier-');

        $consumers = [];

        try {
            $before = $limiter->consume(0)->getRemainingTokens();
            self::assertGreaterThanOrEqual(
                self::SIMULTANEOUS_CONSUMERS,
                $before,
                \sprintf('La limite de limiter.%s est passée sous SIMULTANEOUS_CONSUMERS : baisser ce dernier.', $limiterName),
            );

            for ($i = 0; $i < self::SIMULTANEOUS_CONSUMERS; ++$i) {
                $consumers[] = $this->startConsumer($limiterName, $key, $barrier->path());
            }
            $barrier->waitUntilReady(self::TIMEOUT_SECONDS, ...$consumers);
            $barrier->release();

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
            $barrier->release();
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
        } catch (Throwable) {
        }
    }

    private function startConsumer(string $limiterName, string $key, string $barrierPath): Process
    {
        $process = ChildPhpProcess::inTestEnvironment(self::TIMEOUT_SECONDS, self::CONSUMER_SCRIPT, $limiterName, $key, $barrierPath);
        $process->start();

        return $process;
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
