<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Shared\Domain\ValueObject;

use App\Portfolio\Shared\Domain\Exception\InvalidLocaleException;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Point d'audit I3 : `fromString()` remplace `from()` sur les valeurs venues de
 * l'extérieur, pour que l'échec porte une exception métier identifiable plutôt
 * qu'un `\ValueError` générique — le seul moyen de mapper 404 sur la locale
 * sans transformer au passage n'importe quelle autre ValueError en 404.
 */
final class LocaleTest extends TestCase
{
    #[DataProvider('supportedValues')]
    public function testFromStringResolvesASupportedValue(string $value, Locale $expected): void
    {
        self::assertSame($expected, Locale::fromString($value));
    }

    /**
     * @return iterable<string, array{string, Locale}>
     */
    public static function supportedValues(): iterable
    {
        yield 'français' => ['fr', Locale::FR];
        yield 'anglais' => ['en', Locale::EN];
    }

    #[DataProvider('unsupportedValues')]
    public function testFromStringRejectsAnUnsupportedValue(string $value): void
    {
        $this->expectException(InvalidLocaleException::class);

        Locale::fromString($value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsupportedValues(): iterable
    {
        yield 'langue non gérée' => ['de'];
        yield 'chaîne vide' => [''];
        yield 'casse différente' => ['FR'];
        yield 'segment arbitraire' => ['zz'];
    }

    /**
     * Le message doit nommer la valeur fautive : c'est ce qui rend le 404
     * diagnosticable côté logs sans avoir à rejouer la requête.
     */
    public function testFromStringReportsTheOffendingValue(): void
    {
        try {
            Locale::fromString('zz');
            self::fail('InvalidLocaleException attendue.');
        } catch (InvalidLocaleException $exception) {
            self::assertStringContainsString('zz', $exception->getMessage());
        }
    }

    /**
     * Cœur du correctif I3 : l'exception de locale ne doit surtout pas hériter
     * de `\ValueError`. Si c'était le cas, remapper un jour `ValueError` en 404
     * réintroduirait le fourre-tout qu'on vient de supprimer — et l'inverse est
     * vrai aussi : une ValueError sans rapport ne doit pas être confondue avec
     * une locale invalide.
     */
    public function testInvalidLocaleExceptionIsNotAValueError(): void
    {
        $hierarchy = [InvalidLocaleException::class, ...array_values(class_parents(InvalidLocaleException::class))];

        self::assertNotContains(\ValueError::class, $hierarchy);
        self::assertContains(\DomainException::class, $hierarchy);
    }

    /**
     * `from()` reste disponible pour les valeurs déjà validées en amont (champs
     * de DTO bornés par #[Assert\Choice], valeurs relues depuis la base) : son
     * `\ValueError` y signalerait un vrai bug, et doit donc rester un 500.
     */
    public function testFromStillThrowsAValueErrorForInternalMisuse(): void
    {
        $this->expectException(\ValueError::class);

        Locale::from('zz');
    }
}
