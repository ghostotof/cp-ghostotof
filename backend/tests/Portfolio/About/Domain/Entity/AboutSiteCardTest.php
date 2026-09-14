<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\About\Domain\Entity;

use App\Portfolio\About\Domain\Entity\AboutSiteCard;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;

final class AboutSiteCardTest extends TestCase
{
    /**
     * Spec 0003 D1 : l'identite est posee par le constructeur, pas par le
     * flush. Une entite construite est donc deja identifiable, comparable et
     * testable sans base de donnees.
     */
    public function testANewCardIsIdentifiedByAUuidV7BeforeAnyPersistence(): void
    {
        $card = new AboutSiteCard(Locale::FR, 'Architecture', 'Description.', 'layers', 0);

        self::assertInstanceOf(UuidV7::class, $card->getId());
    }

    /**
     * Pin le v7 et non le v4 : deux constructions successives doivent donner
     * des identifiants distincts et croissants, sur quoi repose l'ordre de
     * repli `ORDER BY id` du repository.
     */
    public function testTwoCardsBuiltInSequenceGetDistinctIncreasingIds(): void
    {
        $first = new AboutSiteCard(Locale::FR, 'Architecture', 'Description.', 'layers', 0);
        $second = new AboutSiteCard(Locale::FR, 'Stack technique', 'Description.', 'server', 1);

        self::assertNotSame($first->getId()->toRfc4122(), $second->getId()->toRfc4122());
        self::assertLessThan($second->getId()->toRfc4122(), $first->getId()->toRfc4122());
    }

    public function testConstructAssignsAllFields(): void
    {
        $card = new AboutSiteCard(Locale::FR, 'Architecture', 'Description.', 'layers', 0);

        self::assertSame(Locale::FR, $card->getLocale());
        self::assertSame('Architecture', $card->getTitle());
        self::assertSame('Description.', $card->getDescription());
        self::assertSame('layers', $card->getIconKey());
        self::assertSame(0, $card->getPosition());
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
