<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Tests\Support\ChildPhpProcess;
use App\Tests\Support\PhpSources;
use App\Tests\Support\ProcessBarrier;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Le harnais des tests de concurrence à processus fils, écrit une seule fois
 * (issue #403) : l'environnement que reçoit le fils ({@see ChildPhpProcess}) et
 * la barrière fichier ({@see ProcessBarrier}), côté test comme côté fils.
 *
 * Cinq tests portaient chacun leur copie (#277, #389, #435 ×2, #458) : un
 * correctif sur l'une n'aurait pas atteint les autres. Les tests ci-dessous
 * exercent le protocole sur de vrais processus, puis le dernier refuse qu'une
 * sixième copie réapparaisse.
 */
final class ChildProcessHarnessTest extends TestCase
{
    private const int TIMEOUT_SECONDS = 20;

    private const string AWAITING_CHILD = __DIR__.'/Fixtures/ChildProcessHarness/await-barrier.php';

    /** Une variable que seul ce test pose : ni le shell ni phpunit.dist.xml ne la connaissent. */
    private const string PROBE_VARIABLE = 'CHILD_PROCESS_HARNESS_PROBE';

    /** Laisse au fils le temps de dépasser la barrière, s'il ne l'attendait pas. */
    private const int SETTLE_MICROSECONDS = 300_000;

    public function testAChildWaitsAtTheBarrierUntilTheTestReleasesIt(): void
    {
        $barrier = ProcessBarrier::hold('harness-barrier-');
        $child = ChildPhpProcess::inTestEnvironment(self::TIMEOUT_SECONDS, self::AWAITING_CHILD, $barrier->path());

        try {
            $child->start();
            $barrier->waitUntilReady(self::TIMEOUT_SECONDS, $child);
            usleep(self::SETTLE_MICROSECONDS);

            self::assertTrue($child->isRunning(), "Le fils n'a pas attendu la barrière :\n".$child->getOutput().$child->getErrorOutput());
            self::assertStringNotContainsString('passed', $child->getOutput());

            $barrier->release();
            $child->wait();

            self::assertSame(0, $child->getExitCode(), $child->getErrorOutput());
            self::assertSame("ready\npassed\n", $child->getOutput());
        } finally {
            $child->stop(0);
            $barrier->release();
        }
    }

    /**
     * Plusieurs fils, une seule libération : c'est ce qui les fait partir au
     * même instant (RateLimiterConcurrencyTest).
     */
    public function testOneReleaseFreesEveryChild(): void
    {
        $barrier = ProcessBarrier::hold('harness-barrier-');
        $children = [];

        try {
            for ($i = 0; $i < 3; ++$i) {
                $children[] = $child = ChildPhpProcess::inTestEnvironment(self::TIMEOUT_SECONDS, self::AWAITING_CHILD, $barrier->path());
                $child->start();
            }
            $barrier->waitUntilReady(self::TIMEOUT_SECONDS, ...$children);
            $barrier->release();

            foreach ($children as $child) {
                $child->wait();
                self::assertSame(0, $child->getExitCode(), $child->getErrorOutput());
            }
        } finally {
            foreach ($children as $child) {
                $child->stop(0);
            }
            $barrier->release();
        }
    }

    /**
     * Le pid de session annoncé avec « ready » est celui que le test observe
     * ensuite dans pg_stat_activity (ContributionOrderConcurrencyTest).
     */
    public function testTheSessionPidAnnouncedByTheChildIsReturned(): void
    {
        $barrier = ProcessBarrier::hold('harness-barrier-');
        $child = ChildPhpProcess::inTestEnvironment(self::TIMEOUT_SECONDS, self::AWAITING_CHILD, $barrier->path(), '4242');

        try {
            $child->start();

            self::assertSame(4242, $barrier->sessionPidOf($child, self::TIMEOUT_SECONDS));
        } finally {
            $barrier->release();
            $child->wait();
        }
    }

    /**
     * Un test qui attend un pid ne doit pas se contenter d'un « ready » sans :
     * il observerait la session d'un autre.
     */
    public function testAChildThatAnnouncesNoSessionFailsTheTestWaitingForOne(): void
    {
        $barrier = ProcessBarrier::hold('harness-barrier-');
        $child = ChildPhpProcess::inTestEnvironment(self::TIMEOUT_SECONDS, self::AWAITING_CHILD, $barrier->path());

        try {
            $child->start();

            $this->expectException(AssertionFailedError::class);
            $this->expectExceptionMessage("sans pid de session");
            $barrier->sessionPidOf($child, self::TIMEOUT_SECONDS);
        } finally {
            $barrier->release();
            $child->wait();
        }
    }

    /**
     * Un fils mort avant la barrière fait échouer l'attente aussitôt, avec sa
     * sortie d'erreur, au lieu de laisser courir le délai.
     */
    public function testAChildThatStopsBeforeTheBarrierFailsTheWaitWithItsErrorOutput(): void
    {
        $barrier = ProcessBarrier::hold('harness-barrier-');
        $child = ChildPhpProcess::inTestEnvironment(self::TIMEOUT_SECONDS, '-r', 'fwrite(STDERR, "mise en place impossible"); exit(2);');

        try {
            $child->start();

            $this->expectException(AssertionFailedError::class);
            $this->expectExceptionMessage('mise en place impossible');
            $barrier->waitUntilReady(self::TIMEOUT_SECONDS, $child);
        } finally {
            $barrier->release();
        }
    }

    public function testReleasingRemovesTheFileAndCanBeRepeated(): void
    {
        $barrier = ProcessBarrier::hold('harness-barrier-');
        self::assertFileExists($barrier->path());

        $barrier->release();
        $barrier->release();

        self::assertFileDoesNotExist($barrier->path());
    }

    /**
     * Les variables que phpunit.dist.xml force ne vivent que dans `$_SERVER` :
     * le fils les reçoit, et elles l'emportent sur celles du shell — une vraie
     * clé d'API exportée ne doit pas remplacer la valeur factice.
     */
    public function testAChildReceivesTheServerVariablesOverThoseOfTheShell(): void
    {
        $previous = $_SERVER[self::PROBE_VARIABLE] ?? null;
        $_SERVER[self::PROBE_VARIABLE] = 'from-phpunit';
        putenv(self::PROBE_VARIABLE.'=from-shell');

        try {
            $child = ChildPhpProcess::inTestEnvironment(self::TIMEOUT_SECONDS, '-r', 'echo getenv("'.self::PROBE_VARIABLE.'"), "\n", getenv("APP_ENV");');
            $child->mustRun();

            self::assertSame("from-phpunit\ntest", $child->getOutput());
        } finally {
            putenv(self::PROBE_VARIABLE);
            if (null === $previous) {
                unset($_SERVER[self::PROBE_VARIABLE]);
            } else {
                $_SERVER[self::PROBE_VARIABLE] = $previous;
            }
        }
    }

    /**
     * Ni `flock()` hors de ProcessBarrier, ni copie de `$_SERVER` hors de
     * ChildPhpProcess : un nouveau test de concurrence passe par eux. Par
     * jetons, comme les autres recenseurs ({@see PhpSources}) : un appel est un
     * nom suivi de `(`, une copie un `foreach ($_SERVER`. Ne voit pas une copie
     * écrite autrement (`array_filter($_SERVER, …)`), ni une barrière qui ne
     * serait pas un verrou de fichier.
     */
    public function testNoTestRewritesTheBarrierOrTheEnvironmentTransmission(): void
    {
        $owners = [
            'flock' => (string) (new ReflectionClass(ProcessBarrier::class))->getFileName(),
            'foreach ($_SERVER' => (string) (new ReflectionClass(ChildPhpProcess::class))->getFileName(),
        ];
        $tests = \dirname(__DIR__);
        $offenders = [];

        foreach (PhpSources::files($tests) as $path) {
            $tokens = PhpSources::significantTokens((string) file_get_contents($path));
            foreach ($tokens as $index => $token) {
                $next = $tokens[$index + 1] ?? null;
                $afterNext = $tokens[$index + 2] ?? null;
                $construct = match (true) {
                    'flock' === ltrim($token->text, '\\') && '(' === $next?->text => 'flock',
                    $token->is(\T_FOREACH) && '(' === $next?->text && '$_SERVER' === $afterNext?->text => 'foreach ($_SERVER',
                    default => null,
                };
                if (null !== $construct && $path !== $owners[$construct]) {
                    $offenders[] = \sprintf('%s:%d %s', substr($path, \strlen($tests) + 1), $token->line, $construct);
                }
            }
        }

        self::assertSame([], $offenders, 'La barrière s\'écrit avec ProcessBarrier, l\'environnement du fils avec ChildPhpProcess::inTestEnvironment() (issue #403).');
    }
}
