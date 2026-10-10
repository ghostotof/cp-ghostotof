<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Tests\Support\ChildPhpProcess;
use App\Tests\Support\PhpSources;
use DomainException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SimpleXMLElement;
use Symfony\Component\ErrorHandler\Exception\FlattenException;
use Symfony\Component\Process\Process;

/**
 * Sous PHPUnit, les traces d'exception ne portent pas les arguments des appels
 * (issue #420) : `#[SensitiveParameter]` (#411, #414) ne couvre que `src/`, le
 * vendor garde les siens. Le pourquoi, ce que le réglage couvre et ce qu'il ne
 * couvre pas : `.claude/rules/backend-outillage.md`.
 *
 * Deux preuves, parce qu'une seule serait creuse en CI, où `setup-php` charge
 * `php.ini-production`, qui pose déjà la directive : la lecture de
 * `phpunit.dist.xml` épingle le réglage là où il agit, sur le poste du
 * développeur (aucun fichier ini du conteneur ne la pose, valeur intégrée Off) ;
 * les tests à l'exécution prouvent qu'il prend effet.
 */
final class ExceptionArgumentsHiddenInTestsTest extends TestCase
{
    /** Assez court pour que `getTraceAsString()`, qui tronque à 15 caractères, l'écrive en entier si les arguments étaient gardés. */
    private const string ARGUMENT = 'arg-420';

    private const string DIRECTIVE = 'zend.exception_ignore_args';

    public function testPhpUnitConfigurationDropsCallArgumentsFromExceptionTraces(): void
    {
        $path = $this->backendDirectory().'/phpunit.dist.xml';
        self::assertFileIsReadable($path);

        $configuration = new SimpleXMLElement((string) file_get_contents($path));
        $settings = $configuration->xpath('/phpunit/php/ini[@name="'.self::DIRECTIVE.'"]/@value');

        self::assertIsArray($settings);
        self::assertSame(['1'], array_map(strval(...), $settings), 'phpunit.dist.xml pose zend.exception_ignore_args à 1 (issue #420).');
    }

    public function testAnExceptionTraceCarriesNoCallArgumentUnderPhpUnit(): void
    {
        $this->requireTheSettingUnlessDisabledLocally();
        $exception = $this->thrownBy(self::ARGUMENT);

        $frame = $exception->getTrace()[0] ?? [];
        self::assertSame('throwWith', $frame['function'] ?? null, 'La première frame est celle de la méthode qui a reçu l\'argument.');
        self::assertArrayNotHasKey('args', $frame);
        self::assertStringNotContainsString(self::ARGUMENT, $exception->getTraceAsString());
    }

    /**
     * Le chemin du vendor que les gardes de #411 et #414 ne voient pas : la
     * trace que Symfony aplatit pour `ErrorDetailsStamp` de Messenger et pour
     * le corps d'erreur en debug d'une route hors API Platform
     * (`ProblemNormalizer` ; celui d'API Platform retire déjà les arguments).
     *
     * Seule la frame de la méthode est lue : PHP garde, même directive à On,
     * le chemin du fichier inclus dans la frame d'un `require` (celle du
     * script `phpunit`), qui n'est pas un argument d'appel.
     */
    public function testAFlattenedExceptionCarriesNoCallArgumentUnderPhpUnit(): void
    {
        $this->requireTheSettingUnlessDisabledLocally();
        $flattened = FlattenException::createFromThrowable($this->thrownBy(self::ARGUMENT));

        $frames = array_filter($flattened->getTrace(), static fn (mixed $frame): bool => \is_array($frame) && 'throwWith' === ($frame['function'] ?? null));
        self::assertSame([[]], array_column($frames, 'args'), 'Une seule frame de la méthode qui a reçu l\'argument, sans argument.');
        self::assertStringNotContainsString(self::ARGUMENT, $flattened->getTraceAsString());
    }

    /**
     * Un processus `php` lancé par un test ne reçoit pas le `<ini>` de
     * PHPUnit, et sa sortie d'erreur, trace comprise, finit dans le message
     * d'échec du test qui l'a lancé.
     */
    public function testAChildPhpProcessInheritsTheSettingOfPhpUnit(): void
    {
        $child = new Process(ChildPhpProcess::command('-r', 'echo ini_get("'.self::DIRECTIVE.'");'));
        $child->mustRun();

        self::assertSame((string) \ini_get(self::DIRECTIVE), $child->getOutput());
    }

    public function testNoTestStartsPhpWithoutChildPhpProcess(): void
    {
        $helper = (new ReflectionClass(ChildPhpProcess::class))->getFileName();
        $offenders = [];
        foreach (PhpSources::files($this->backendDirectory().'/tests') as $path) {
            if ($path === $helper) {
                continue;
            }
            foreach (PhpSources::significantTokens((string) file_get_contents($path)) as $token) {
                if ('PHP_BINARY' === ltrim($token->text, '\\')) {
                    $offenders[] = \sprintf('%s:%d', substr($path, \strlen($this->backendDirectory()) + 1), $token->line);
                }
            }
        }

        self::assertSame([], $offenders, 'Un test lance PHP par ChildPhpProcess::command(), qui lui transmet zend.exception_ignore_args (issue #420).');
    }

    /**
     * Le `phpunit.xml` local (ignoré par git), que PHPUnit lit à la place de
     * `phpunit.dist.xml`, est l'échappatoire documentée pour déboguer avec les
     * arguments : la suite le signale par un saut plutôt que par un rouge
     * qu'on apprendrait à ignorer. Partout ailleurs, CI comprise, le réglage
     * est exigé.
     */
    private function requireTheSettingUnlessDisabledLocally(): void
    {
        if ('1' !== \ini_get(self::DIRECTIVE) && is_file($this->backendDirectory().'/phpunit.xml')) {
            self::markTestSkipped('zend.exception_ignore_args désactivé par un phpunit.xml local : les traces portent les arguments des appels.');
        }

        self::assertSame('1', \ini_get(self::DIRECTIVE));
    }

    private function backendDirectory(): string
    {
        return \dirname(__DIR__, 2);
    }

    private function thrownBy(string $argument): DomainException
    {
        try {
            $this->throwWith($argument);
        } catch (DomainException $exception) {
            return $exception;
        }
    }

    private function throwWith(string $argument): never
    {
        throw new DomainException(\sprintf('Exception de preuve, l\'argument (%d caractères) reste hors du message.', \strlen($argument)));
    }
}
