<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\About\Domain\Entity;

use App\Portfolio\About\Domain\Entity\AboutSiteCard;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use PHPUnit\Framework\TestCase;

final class AboutSiteCardTest extends TestCase
{
    public function testConstructAssignsAllFields(): void
    {
        $card = new AboutSiteCard(Locale::FR, 'Architecture', 'Description.', 'layers', 0);

        self::assertSame(Locale::FR, $card->getLocale());
        self::assertSame('Architecture', $card->getTitle());
        self::assertSame('Description.', $card->getDescription());
        self::assertSame('layers', $card->getIconKey());
        self::assertSame(0, $card->getPosition());
        self::assertNull($card->getId());
    }

    public function testIconKeyIsNullable(): void
    {
        $card = new AboutSiteCard(Locale::FR, 'Titre', 'Description.', null, 0);

        self::assertNull($card->getIconKey());
    }

    public function testUpdateChangesEverythingExceptLocale(): void
    {
        $card = new AboutSiteCard(Locale::EN, 'Architecture', 'Description.', 'layers', 0);

        $card->update('Stack', 'New description.', 'server', 1);

        self::assertSame(Locale::EN, $card->getLocale());
        self::assertSame('Stack', $card->getTitle());
        self::assertSame('New description.', $card->getDescription());
        self::assertSame('server', $card->getIconKey());
        self::assertSame(1, $card->getPosition());
    }
}
