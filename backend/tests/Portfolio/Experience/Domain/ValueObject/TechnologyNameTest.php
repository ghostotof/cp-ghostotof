<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Experience\Domain\ValueObject;

use App\Portfolio\Experience\Domain\Exception\InvalidTechnologyNameException;
use App\Portfolio\Experience\Domain\ValueObject\TechnologyName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TechnologyNameTest extends TestCase
{
    /**
     * Issue #386 : deux saisies qui s'affichent pareil sur la page Expériences
     * sont le même nom, sans quoi le contrôle d'unicité les laisse passer.
     */
    public function testTheNameIsTrimmed(): void
    {
        self::assertSame('PHP', TechnologyName::fromString(" \tPHP \n")->value);
    }

    public function testTheInnerSpacesAreKept(): void
    {
        self::assertSame('MySQL / PostgreSQL', TechnologyName::fromString('MySQL / PostgreSQL')->value);
    }

    /** @return iterable<string, array{string}> */
    public static function blankNames(): iterable
    {
        yield 'vide' => [''];
        yield 'espaces' => ['   '];
        yield 'tabulation et retour à la ligne' => ["\t\n"];
    }

    #[DataProvider('blankNames')]
    public function testABlankNameIsRefused(string $name): void
    {
        $this->expectException(InvalidTechnologyNameException::class);
        $this->expectExceptionMessage('ne peut pas être vide');

        TechnologyName::fromString($name);
    }

    /**
     * Revue de #386 : `trim()` ne retire que les blancs ASCII. Une espace
     * insécable ou un caractère de largeur nulle, collés depuis une page web,
     * refaisaient le doublon que le rognage devait empêcher.
     *
     * @return iterable<string, array{string}>
     */
    public static function unicodeBlanks(): iterable
    {
        yield 'espace insécable' => ["\u{00A0}PHP\u{00A0}"];
        yield 'espace idéographique' => ["\u{3000}PHP"];
        yield 'espace de largeur nulle' => ["\u{200B}PHP"];
        yield 'indicateur d\'ordre des octets' => ["\u{FEFF}PHP"];
    }

    #[DataProvider('unicodeBlanks')]
    public function testUnicodeBlanksAroundTheNameAreTrimmedToo(string $name): void
    {
        self::assertSame('PHP', TechnologyName::fromString($name)->value);
    }

    public function testANameMadeOfUnicodeBlanksOnlyIsRefused(): void
    {
        $this->expectException(InvalidTechnologyNameException::class);
        $this->expectExceptionMessage('ne peut pas être vide');

        TechnologyName::fromString("\u{00A0}\u{200B}");
    }

    /**
     * Revue de #386 : PostgreSQL refuse l'octet NUL dans un `varchar`, et
     * l'exception DBAL sortait en 500 `critical`. Aucun caractère de contrôle
     * n'a sa place dans un nom affiché.
     *
     * @return iterable<string, array{string}>
     */
    public static function namesWithControlCharacters(): iterable
    {
        yield 'octet NUL' => ["P\0HP"];
        yield 'tabulation au milieu' => ["P\tHP"];
        yield 'retour à la ligne au milieu' => ["P\nHP"];
    }

    #[DataProvider('namesWithControlCharacters')]
    public function testANameWithAControlCharacterIsRefused(string $name): void
    {
        $this->expectException(InvalidTechnologyNameException::class);
        $this->expectExceptionMessage('caractère de contrôle');

        TechnologyName::fromString($name);
    }

    /** L'argv de la CLI peut porter n'importe quels octets, que PostgreSQL refuserait. */
    public function testANameThatIsNotValidUtf8IsRefused(): void
    {
        $this->expectException(InvalidTechnologyNameException::class);
        $this->expectExceptionMessage('UTF-8');

        TechnologyName::fromString("PHP\xff");
    }

    /** La borne est celle de la colonne, en caractères comme PostgreSQL les compte. */
    public function testTheLongestNameTheColumnHoldsIsAccepted(): void
    {
        $name = str_repeat('é', TechnologyName::MAX_LENGTH);

        self::assertSame($name, TechnologyName::fromString($name)->value);
    }

    public function testALongerNameIsRefused(): void
    {
        $this->expectException(InvalidTechnologyNameException::class);
        $this->expectExceptionMessage((string) TechnologyName::MAX_LENGTH);

        TechnologyName::fromString(str_repeat('a', TechnologyName::MAX_LENGTH + 1));
    }

    /** Les espaces retirés ne comptent pas dans la longueur. */
    public function testTheLengthIsMeasuredAfterTrimming(): void
    {
        $name = str_repeat('a', TechnologyName::MAX_LENGTH);

        self::assertSame($name, TechnologyName::fromString(' '.$name.' ')->value);
    }
}
