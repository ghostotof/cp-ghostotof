<?php

declare(strict_types=1);

namespace App\Tests\Security;

use DomainException;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;
use Symfony\Component\ErrorHandler\Exception\FlattenException;

/**
 * Sous PHPUnit, les traces d'exception ne portent pas les arguments des appels
 * (issue #420, suite de #411 et #414).
 *
 * #411 et #414 cachent les secrets de `src/` paramètre par paramètre
 * (`#[SensitiveParameter]`). Le vendor leur échappe : `Cookie::create($value)`,
 * `JWTManager::parse($token)`, `LcobucciJWTEncoder::decode($token)`… gardent
 * leurs arguments, et le risque visé est justement celui d'une trace de test
 * collée dans un ticket ou une session d'assistant. `zend.exception_ignore_args`
 * couvre `src/` et le vendor d'un seul réglage, comme `php.prod.ini` le fait en
 * déployé ({@see \App\Tests\Security\ExceptionArgumentsHiddenInProductionTest}).
 *
 * Deux preuves, parce qu'une seule serait creuse en CI : `setup-php` y charge
 * `php.ini-production`, qui pose déjà la directive à On. Le test à l'exécution
 * passerait donc en CI même sans `phpunit.dist.xml` ; c'est la lecture du
 * fichier qui épingle le réglage là où il agit sur le poste du développeur,
 * dont le conteneur n'a pas de `php.ini` (valeur intégrée : Off).
 */
final class ExceptionArgumentsHiddenInTestsTest extends TestCase
{
    /** Assez court pour que `getTraceAsString()`, qui tronque à 15 caractères, l'écrive en entier si les arguments étaient gardés. */
    private const string ARGUMENT = 'arg-420';

    public function testPhpUnitConfigurationDropsCallArgumentsFromExceptionTraces(): void
    {
        $path = \dirname(__DIR__, 2).'/phpunit.dist.xml';
        self::assertFileIsReadable($path);

        $configuration = new SimpleXMLElement((string) file_get_contents($path));
        $settings = $configuration->xpath('/phpunit/php/ini[@name="zend.exception_ignore_args"]/@value');

        self::assertIsArray($settings);
        self::assertSame(['1'], array_map(strval(...), $settings), 'phpunit.dist.xml pose zend.exception_ignore_args à 1 (issue #420).');
    }

    public function testAnExceptionTraceCarriesNoCallArgumentUnderPhpUnit(): void
    {
        $exception = $this->thrownBy(self::ARGUMENT);

        self::assertSame('1', \ini_get('zend.exception_ignore_args'));
        $frame = $exception->getTrace()[0] ?? [];
        self::assertSame('throwWith', $frame['function'] ?? null, 'La première frame est celle de la méthode qui a reçu l\'argument.');
        self::assertArrayNotHasKey('args', $frame);
        self::assertStringNotContainsString(self::ARGUMENT, $exception->getTraceAsString());
    }

    /**
     * Le chemin du vendor que les gardes de #411 et #414 ne voient pas : la
     * trace que Symfony aplatit pour le corps d'erreur en debug
     * (`ProblemNormalizer`) et pour `ErrorDetailsStamp` de Messenger.
     *
     * Seule la frame de la méthode est lue : PHP garde, même directive à On,
     * le chemin du fichier inclus dans la frame d'un `require` (celle du
     * script `phpunit`), qui n'est pas un argument d'appel.
     */
    public function testAFlattenedExceptionCarriesNoCallArgumentUnderPhpUnit(): void
    {
        $trace = FlattenException::createFromThrowable($this->thrownBy(self::ARGUMENT))->getTrace();

        $frames = array_filter($trace, static fn (mixed $frame): bool => \is_array($frame) && 'throwWith' === ($frame['function'] ?? null));
        self::assertSame([[]], array_column($frames, 'args'), 'Une seule frame de la méthode qui a reçu l\'argument, sans argument.');
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
