<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Experience\Domain\ValueObject;

use App\Portfolio\Experience\Domain\Exception\InvalidExperienceYearsException;
use App\Portfolio\Experience\Domain\ValueObject\ExperienceYears;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExperienceYearsTest extends TestCase
{
    /**
     * Issue #372 : la durée est publiée telle quelle par GET
     * /api/experience/technologies. Une valeur non finie (INF, NaN) ne
     * s'encode pas en JSON et met la route publique en 500 pour tout le
     * monde ; une valeur négative ou aberrante s'afficherait telle quelle.
     *
     * @return iterable<string, array{float}>
     */
    public static function outOfRangeYears(): iterable
    {
        yield 'infini positif (json_decode de 1e999)' => [\INF];
        yield 'infini négatif' => [-\INF];
        yield 'NaN' => [\NAN];
        yield 'négatif' => [-0.5];
        yield 'au-delà de la borne haute' => [100.5];
    }

    #[DataProvider('outOfRangeYears')]
    public function testRejectsYearsOutOfRange(float $years): void
    {
        $this->expectException(InvalidExperienceYearsException::class);

        ExperienceYears::fromFloat($years);
    }

    /**
     * Les deux bornes sont incluses : 0 est une durée légitime (technologie
     * tout juste abordée), et la borne haute ne doit jamais gêner une vraie saisie.
     */
    public function testBothBoundsAreAccepted(): void
    {
        self::assertSame(0.0, ExperienceYears::fromFloat(0.0)->value);
        self::assertSame(ExperienceYears::MAX, ExperienceYears::fromFloat(ExperienceYears::MAX)->value);
    }

    /**
     * `-0.0 < 0.0` est faux : le zéro signé passe la borne basse, mais
     * `json_encode(-0.0)` publie « -0 ». Il est ramené au zéro ordinaire.
     */
    public function testANegativeZeroIsNormalisedToZero(): void
    {
        $years = ExperienceYears::fromFloat(-0.0);

        self::assertSame('0', json_encode($years->value));
    }

    /**
     * Le message rend la valeur reçue exacte : `%s` l'arrondirait à 14
     * chiffres et refuserait « 100.00000000000001 » en annonçant « reçu : 100 ».
     */
    public function testTheRejectionNamesTheExactValueReceived(): void
    {
        try {
            ExperienceYears::fromFloat(100.00000000000001);
            self::fail('Une durée au-delà de la borne haute aurait dû être refusée.');
        } catch (InvalidExperienceYearsException $exception) {
            self::assertSame(
                'Le temps cumulé doit être un nombre compris entre 0 et 100 ans (reçu : 100.00000000000001).',
                $exception->getMessage(),
            );
        }
    }

    public function testNamesANonFiniteValueWithoutAnyPhpWarning(): void
    {
        $this->expectExceptionMessage('(reçu : NAN)');

        ExperienceYears::fromFloat(\NAN);
    }

    /**
     * Entrée textuelle de la commande CLI : `is_numeric('1e999')` est vrai et
     * `(float)` le rend en INF, la conversion seule ne protège donc de rien.
     */
    public function testFromStringAppliesTheSameBounds(): void
    {
        self::assertSame(13.5, ExperienceYears::fromString('13.5')->value);

        $this->expectException(InvalidExperienceYearsException::class);
        $this->expectExceptionMessage('compris entre 0 et 100 ans (reçu : INF)');

        ExperienceYears::fromString('1e999');
    }

    public function testFromStringRejectsANonNumericInput(): void
    {
        $this->expectException(InvalidExperienceYearsException::class);
        $this->expectExceptionMessage('Le temps cumulé doit être un nombre (ex. 13.5).');

        ExperienceYears::fromString('not-a-number');
    }
}
