<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Tests\Support\ChildPhpProcess;
use App\Tests\Support\PhpSources;
use App\Tests\Support\ProcessBarrier;
use PhpToken;
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

    private const string FLOCK = 'flock(';

    private const string SERVER_COPY = 'foreach ($_SERVER';

    private const string PROCESS_ON_COMMAND = 'new Process(ChildPhpProcess::command(';

    /** Une variable que phpunit.dist.xml force à une valeur factice, et que le .env laisse vide. */
    private const string FORCED_VARIABLE = 'ANTHROPIC_API_KEY';

    /**
     * Les seuls tests qui construisent un Process sur ChildPhpProcess::command()
     * sans passer par inTestEnvironment(), chacun pour une raison écrite.
     */
    private const array OWN_ENVIRONMENT = [
        // Simule un pod : l'environnement du fils est isolé à dessein, sans
        // celui de PHPUnit, qui fausserait chaque comparaison.
        'Shared/Infrastructure/Dotenv/CompiledDotenvNeverMasksPodVariablesTest.php',
    ];

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
            // Une notice du CLI sur la sortie ne doit pas compter : seul l'ordre importe.
            self::assertMatchesRegularExpression('/^ready$.*^passed$/ms', $child->getOutput());
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
            usleep(self::SETTLE_MICROSECONDS);
            foreach ($children as $child) {
                self::assertTrue($child->isRunning(), "Un fils n'a pas attendu la barrière :\n".$child->getOutput().$child->getErrorOutput());
                self::assertStringNotContainsString('passed', $child->getOutput());
            }

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
            if ($child->isStarted()) {
                $child->wait();
            }
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
            $this->expectExceptionMessage('sans pid de session');
            $barrier->sessionPidOf($child, self::TIMEOUT_SECONDS);
        } finally {
            $barrier->release();
            if ($child->isStarted()) {
                $child->wait();
            }
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
     * La menace que nomme ChildPhpProcess, de bout en bout : le fils démarre
     * par le bootstrap des tests, donc par Dotenv, et ni une vraie clé d'API
     * exportée dans le shell ni le .env.local ne doivent remplacer la valeur
     * factice que phpunit.dist.xml force. Comparées par empreinte : un échec
     * n'écrit jamais la clé dans son message.
     *
     * Ce test reste vert si la copie de `$_SERVER` disparaît (Process hérite
     * aussi de `$_ENV`, voir ChildPhpProcess) : c'est le précédent qui la
     * pince, celui-ci pince le résultat, quel que soit le chemin.
     */
    public function testAForcedVariableSurvivesTheShellAndTheDotenvOfTheChild(): void
    {
        $forced = $_SERVER[self::FORCED_VARIABLE] ?? null;
        self::assertIsString($forced);
        self::assertNotSame('', $forced, 'phpunit.dist.xml ne force plus '.self::FORCED_VARIABLE.' : choisir une autre variable forcée.');
        $previous = getenv(self::FORCED_VARIABLE);
        putenv(self::FORCED_VARIABLE.'='.bin2hex(random_bytes(16)));

        try {
            $child = ChildPhpProcess::inTestEnvironment(
                self::TIMEOUT_SECONDS,
                '-r',
                'require '.var_export(\dirname(__DIR__).'/bootstrap.php', true).'; echo hash("sha256", (string) ($_SERVER["'.self::FORCED_VARIABLE.'"] ?? ""));',
            );
            $child->mustRun();

            self::assertSame(hash('sha256', $forced), $child->getOutput(), 'Le fils ne voit pas la valeur forcée par phpunit.dist.xml.');
        } finally {
            putenv(false === $previous ? self::FORCED_VARIABLE : self::FORCED_VARIABLE.'='.$previous);
        }
    }

    /**
     * Ni `flock()` hors de ProcessBarrier, ni copie de `$_SERVER` hors de
     * ChildPhpProcess, ni `new Process(ChildPhpProcess::command(…` qui
     * oublierait l'environnement : un nouveau test de concurrence passe par
     * eux. Par jetons, comme les autres recenseurs ({@see PhpSources}) : un
     * appel est un nom suivi de `(`, une copie un `foreach ($_SERVER`. Ne voit
     * pas une copie écrite autrement (`array_filter($_SERVER, …)`), un
     * `proc_open()`, ni une barrière qui ne serait pas un verrou de fichier.
     */
    public function testNoTestRewritesTheBarrierOrTheEnvironmentTransmission(): void
    {
        $owners = [
            self::FLOCK => (string) (new ReflectionClass(ProcessBarrier::class))->getFileName(),
            self::SERVER_COPY => (string) (new ReflectionClass(ChildPhpProcess::class))->getFileName(),
            self::PROCESS_ON_COMMAND => (string) (new ReflectionClass(ChildPhpProcess::class))->getFileName(),
        ];
        $tests = \dirname(__DIR__);
        $offenders = [];
        $exemptionsUsed = [];

        foreach (PhpSources::files($tests) as $path) {
            $relative = substr($path, \strlen($tests) + 1);
            $tokens = PhpSources::significantTokens((string) file_get_contents($path));
            foreach (array_keys($tokens) as $index) {
                $construct = self::constructAt($tokens, $index);
                if (null === $construct || $path === $owners[$construct]) {
                    continue;
                }
                if (self::PROCESS_ON_COMMAND === $construct && \in_array($relative, self::OWN_ENVIRONMENT, true)) {
                    $exemptionsUsed[$relative] = $relative;

                    continue;
                }
                $offenders[] = \sprintf('%s:%d %s', $relative, $tokens[$index]->line, $construct);
            }
        }

        self::assertSame([], $offenders, 'La barrière s\'écrit avec ProcessBarrier, l\'environnement du fils avec ChildPhpProcess::inTestEnvironment() (issue #403).');
        self::assertSame(self::OWN_ENVIRONMENT, array_values($exemptionsUsed), 'Exemption devenue sans objet : la retirer de OWN_ENVIRONMENT.');
    }

    /**
     * Le motif que commence le jeton à cette position, s'il en commence un.
     *
     * @param list<PhpToken> $tokens
     */
    private static function constructAt(array $tokens, int $index): ?string
    {
        $token = $tokens[$index];
        $previous = $tokens[$index - 1] ?? null;
        $next = $tokens[$index + 1] ?? null;
        // Une méthode homonyme (`->flock(`, `::flock(`) ou sa déclaration n'est pas la fonction native.
        $isMember = null !== $previous && \in_array($previous->text, ['->', '?->', '::', 'function'], true);

        return match (true) {
            !$isMember && 'flock' === ltrim($token->text, '\\') && '(' === $next?->text => self::FLOCK,
            $token->is(\T_FOREACH) && '(' === $next?->text && '$_SERVER' === ($tokens[$index + 2] ?? null)?->text => self::SERVER_COPY,
            $token->is(\T_NEW) && self::buildsAProcessOnCommand(\array_slice($tokens, $index + 1, 6)) => self::PROCESS_ON_COMMAND,
            default => null,
        };
    }

    /**
     * `new Process(ChildPhpProcess::command(`, la classe `Process` écrite
     * courte ou qualifiée.
     *
     * @param list<PhpToken> $following les six jetons après `new`
     */
    private static function buildsAProcessOnCommand(array $following): bool
    {
        $shortNames = array_map(static fn (PhpToken $token): string => (string) array_last(explode('\\', $token->text)), $following);

        return ['Process', '(', 'ChildPhpProcess', '::', 'command', '('] === $shortNames;
    }
}
