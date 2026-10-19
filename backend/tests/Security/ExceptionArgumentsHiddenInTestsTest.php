<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Tests\Support\ChildPhpProcess;
use App\Tests\Support\PhpSources;
use App\Tests\Support\RepositoryFiles;
use DomainException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SimpleXMLElement;
use Symfony\Component\ErrorHandler\Exception\FlattenException;

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

    public function testPhpUnitConfigurationDropsCallArgumentsFromExceptionTraces(): void
    {
        $path = RepositoryFiles::backend('phpunit.dist.xml');
        self::assertFileIsReadable($path);

        self::assertSame(['1'], $this->declaredValues($path), 'phpunit.dist.xml pose zend.exception_ignore_args à 1 (issue #420).');
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
     * d'échec du test qui l'a lancé. L'exécution seule serait creuse en CI,
     * où l'enfant charge aussi `php.ini-production` : la forme de la commande
     * est vérifiée en plus.
     */
    public function testAChildPhpProcessInheritsTheSettingOfPhpUnit(): void
    {
        $value = (string) \ini_get(ChildPhpProcess::DIRECTIVE);
        self::assertSame(['-d', ChildPhpProcess::DIRECTIVE.'='.$value, 'script.php'], \array_slice(ChildPhpProcess::command('script.php'), 1), 'La directive passe avant le script.');

        $child = ChildPhpProcess::inTestEnvironment(20, '-r', 'echo ini_get("'.ChildPhpProcess::DIRECTIVE.'");');
        $child->mustRun();

        // `-d …=On` est relu `1` par l'enfant : on compare l'état, pas l'écriture.
        self::assertSame(self::isOn($value), self::isOn($child->getOutput()));
    }

    /**
     * Ne voit que `PHP_BINARY`, la façon dont la suite lance PHP. Un `'php'`
     * littéral, `PhpExecutableFinder`, `PHP_BINDIR` ou un `bin/console` lancé
     * par son shebang lui échappent : un `'php'` en tête de tableau est aussi
     * une donnée de test courante (un nom de technologie), qu'aucune
     * heuristique par jetons ne distingue d'une commande.
     */
    public function testNoTestStartsPhpWithoutChildPhpProcess(): void
    {
        $helper = (new ReflectionClass(ChildPhpProcess::class))->getFileName();
        $offenders = [];
        foreach (PhpSources::files(RepositoryFiles::backend('tests')) as $path) {
            if ($path === $helper) {
                continue;
            }
            foreach (PhpSources::significantTokens(RepositoryFiles::read($path)) as $token) {
                if ('PHP_BINARY' === ltrim($token->text, '\\')) {
                    $offenders[] = \sprintf('%s:%d', substr($path, \strlen(RepositoryFiles::backend()) + 1), $token->line);
                }
            }
        }

        self::assertSame([], $offenders, 'Un test lance PHP par ChildPhpProcess::command(), qui lui transmet zend.exception_ignore_args (issue #420).');
    }

    /**
     * Le `phpunit.xml` local (ignoré par git), que PHPUnit lit à la place de
     * `phpunit.dist.xml`, est l'échappatoire documentée pour déboguer avec les
     * arguments : quand il pose **explicitement** la directive à Off, la suite
     * le signale par un saut plutôt que par un rouge qu'on apprendrait à
     * ignorer. Un `phpunit.xml` qui l'omet (copié d'une dist d'avant #420)
     * n'est pas un choix : le test échoue, comme partout ailleurs, CI comprise.
     */
    private function requireTheSettingUnlessDisabledLocally(): void
    {
        $local = RepositoryFiles::backend('phpunit.xml');
        $declaredOff = is_file($local) && array_any($this->declaredValues($local), static fn (string $value): bool => !self::isOn($value));
        if ($declaredOff && !self::isOn((string) \ini_get(ChildPhpProcess::DIRECTIVE))) {
            self::markTestSkipped('zend.exception_ignore_args désactivé par un phpunit.xml local : les traces portent les arguments des appels.');
        }

        self::assertTrue(self::isOn((string) \ini_get(ChildPhpProcess::DIRECTIVE)), 'zend.exception_ignore_args est actif sous PHPUnit (issue #420).');
    }

    /** `1`, `On`, `true`, `yes` : ce que PHP lit comme vrai pour une directive booléenne. */
    private static function isOn(string $value): bool
    {
        return filter_var($value, \FILTER_VALIDATE_BOOL);
    }

    /**
     * @return list<string> les valeurs que ce fichier de configuration PHPUnit donne à la directive
     */
    private function declaredValues(string $path): array
    {
        $settings = new SimpleXMLElement(RepositoryFiles::read($path))->xpath('/phpunit/php/ini[@name="'.ChildPhpProcess::DIRECTIVE.'"]/@value');

        return array_values(array_map(strval(...), \is_array($settings) ? $settings : []));
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
