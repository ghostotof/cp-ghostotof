<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\About\Domain\Entity;

use App\Portfolio\About\Domain\Entity\AboutMeCard;
use App\Portfolio\About\Domain\ValueObject\AboutMeCardCategory;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;

final class AboutMeCardTest extends TestCase
{
    /**
     * Spec 0003 D1 : l'identite est posee par le constructeur, pas par le
     * flush. Une entite construite est donc deja identifiable, comparable et
     * testable sans base de donnees.
     */
    public function testANewCardIsIdentifiedByAUuidV7BeforeAnyPersistence(): void
    {
        $card = new AboutMeCard(Locale::FR, AboutMeCardCategory::TECHNICAL, 'Développeur', 'Description.', 'code', 0);

        self::assertInstanceOf(UuidV7::class, $card->getId());
    }

    /**
     * Pin le v7 et non le v4 : deux constructions successives doivent donner
     * des identifiants distincts et croissants, sur quoi repose l'ordre de
     * repli `ORDER BY id` du repository.
     */
    public function testTwoCardsBuiltInSequenceGetDistinctIncreasingIds(): void
    {
        $first = new AboutMeCard(Locale::FR, AboutMeCardCategory::TECHNICAL, 'Développeur', 'Description.', 'code', 0);
        $second = new AboutMeCard(Locale::FR, AboutMeCardCategory::HOBBY, 'Musique', 'Description.', 'guitar', 1);

        self::assertNotSame($first->getId()->toRfc4122(), $second->getId()->toRfc4122());
        self::assertLessThan($second->getId()->toRfc4122(), $first->getId()->toRfc4122());
    }

    public function testConstructAssignsAllFields(): void
    {
        $card = new AboutMeCard(Locale::FR, AboutMeCardCategory::TECHNICAL, 'Développeur', 'Description.', 'code', 0);

        self::assertSame(Locale::FR, $card->getLocale());
        self::assertSame(AboutMeCardCategory::TECHNICAL, $card->getCategory());
        self::assertSame('Développeur', $card->getTitle());
        self::assertSame('Description.', $card->getDescription());
        self::assertSame('code', $card->getIconKey());
        self::assertSame(0, $card->getPosition());
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
