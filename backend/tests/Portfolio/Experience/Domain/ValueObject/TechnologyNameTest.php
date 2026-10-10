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
