<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\About\Domain\Entity;

use App\Portfolio\About\Domain\Entity\AboutMeCard;
use App\Portfolio\About\Domain\ValueObject\AboutMeCardCategory;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use PHPUnit\Framework\TestCase;

final class AboutMeCardTest extends TestCase
{
    public function testConstructAssignsAllFields(): void
    {
        $card = new AboutMeCard(Locale::FR, AboutMeCardCategory::TECHNICAL, 'Développeur', 'Description.', 'code', 0);

        self::assertSame(Locale::FR, $card->getLocale());
        self::assertSame(AboutMeCardCategory::TECHNICAL, $card->getCategory());
        self::assertSame('Développeur', $card->getTitle());
        self::assertSame('Description.', $card->getDescription());
        self::assertSame('code', $card->getIconKey());
        self::assertSame(0, $card->getPosition());
        self::assertNull($card->getId());
    }

    public function testUpdateChangesEverythingExceptLocaleAndCategory(): void
    {
        $card = new AboutMeCard(Locale::EN, AboutMeCardCategory::HOBBY, 'Musique', 'Description.', 'guitar', 0);

        $card->update('Moto', 'New description.', 'motorbike', 1);

        self::assertSame(Locale::EN, $card->getLocale());
        self::assertSame(AboutMeCardCategory::HOBBY, $card->getCategory());
        self::assertSame('Moto', $card->getTitle());
        self::assertSame('New description.', $card->getDescription());
        self::assertSame('motorbike', $card->getIconKey());
        self::assertSame(1, $card->getPosition());
    }
}
