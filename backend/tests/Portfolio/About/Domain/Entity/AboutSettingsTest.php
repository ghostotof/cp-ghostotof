<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\About\Domain\Entity;

use App\Portfolio\About\Domain\Entity\AboutSettings;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use PHPUnit\Framework\TestCase;

final class AboutSettingsTest extends TestCase
{
    public function testConstructAssignsAllFields(): void
    {
        $settings = new AboutSettings(Locale::FR, 'Site', 'Moi', 'Technique', 'Perso', 'Loisirs');

        self::assertSame(Locale::FR, $settings->getLocale());
        self::assertSame('Site', $settings->getSiteEyebrow());
        self::assertSame('Moi', $settings->getMeEyebrow());
        self::assertSame('Technique', $settings->getTechnicalSubtitle());
        self::assertSame('Perso', $settings->getPersonalSubtitle());
        self::assertSame('Loisirs', $settings->getHobbiesSubtitle());
        self::assertNull($settings->getId());
    }

    public function testUpdateChangesEverythingExceptLocale(): void
    {
        $settings = new AboutSettings(Locale::EN, 'Site', 'Me', 'Technical', 'Personal', 'Hobbies');

        $settings->update('New site', 'New me', 'New technical', 'New personal', 'New hobbies');

        self::assertSame(Locale::EN, $settings->getLocale());
        self::assertSame('New site', $settings->getSiteEyebrow());
        self::assertSame('New me', $settings->getMeEyebrow());
        self::assertSame('New technical', $settings->getTechnicalSubtitle());
        self::assertSame('New personal', $settings->getPersonalSubtitle());
        self::assertSame('New hobbies', $settings->getHobbiesSubtitle());
    }
}
