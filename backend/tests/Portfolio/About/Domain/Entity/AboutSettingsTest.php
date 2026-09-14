<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\About\Domain\Entity;

use App\Portfolio\About\Domain\Entity\AboutSettings;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;

final class AboutSettingsTest extends TestCase
{
    /**
     * Spec 0003 D1 : l'identite est posee par le constructeur, pas par le
     * flush. Une entite construite est donc deja identifiable, comparable et
     * testable sans base de donnees.
     */
    public function testANewSettingsIsIdentifiedByAUuidV7BeforeAnyPersistence(): void
    {
        $settings = new AboutSettings(Locale::FR, 'Site', 'Moi', 'Technique', 'Perso', 'Loisirs');

        self::assertInstanceOf(UuidV7::class, $settings->getId());
    }

    /**
     * Pin le v7 et non le v4 : deux constructions successives doivent donner
     * des identifiants distincts et croissants.
     */
    public function testTwoSettingsBuiltInSequenceGetDistinctIncreasingIds(): void
    {
        $first = new AboutSettings(Locale::FR, 'Site', 'Moi', 'Technique', 'Perso', 'Loisirs');
        $second = new AboutSettings(Locale::EN, 'Site', 'Me', 'Technical', 'Personal', 'Hobbies');

        self::assertNotSame($first->getId()->toRfc4122(), $second->getId()->toRfc4122());
        self::assertLessThan($second->getId()->toRfc4122(), $first->getId()->toRfc4122());
    }

    public function testConstructAssignsAllFields(): void
    {
        $settings = new AboutSettings(Locale::FR, 'Site', 'Moi', 'Technique', 'Perso', 'Loisirs');

        self::assertSame(Locale::FR, $settings->getLocale());
        self::assertSame('Site', $settings->getSiteEyebrow());
        self::assertSame('Moi', $settings->getMeEyebrow());
        self::assertSame('Technique', $settings->getTechnicalSubtitle());
        self::assertSame('Perso', $settings->getPersonalSubtitle());
        self::assertSame('Loisirs', $settings->getHobbiesSubtitle());
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
