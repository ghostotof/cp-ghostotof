<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\About\Domain\Entity;

use App\Portfolio\About\Domain\Entity\AboutMeCard;
use App\Portfolio\About\Domain\ValueObject\AboutMeCardCategory;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
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

    /**
     * Spec 0004 D1 : le groupe de traduction est un identifiant partagé, pas
     * une entité. Une entrée construite sans groupe en reçoit un neuf — elle
     * est donc toujours dans un groupe, fût-il d'une seule langue, ce qui
     * permet à `translation_group` d'être NOT NULL.
     */
    public function testAnEntryBuiltWithoutAGroupGetsAFreshTranslationGroup(): void
    {
        $first = new AboutMeCard(Locale::FR, AboutMeCardCategory::TECHNICAL, 'Développeur', 'Description.', 'code', 0);
        $second = new AboutMeCard(Locale::FR, AboutMeCardCategory::TECHNICAL, 'Développeur', 'Description.', 'code', 0);

        self::assertInstanceOf(UuidV7::class, $first->getTranslationGroup());
        self::assertFalse($first->getTranslationGroup()->equals($second->getTranslationGroup()));
    }

    /**
     * L'autre voie : la version d'une autre langue reçoit le groupe de
     * l'entrée existante dès la construction — c'est ce que font les commandes
     * de peuplement pour apparier FR et EN au même index.
     */
    public function testAnEntryBuiltWithAGroupCarriesIt(): void
    {
        $group = Uuid::v7();

        $entry = new AboutMeCard(Locale::EN, AboutMeCardCategory::TECHNICAL, 'Developer', 'Description.', 'code', 0, $group);

        self::assertTrue($group->equals($entry->getTranslationGroup()));
    }

    public function testAttachToTranslationGroupLinksTheEntryToAnExistingGroup(): void
    {
        $entry = new AboutMeCard(Locale::FR, AboutMeCardCategory::TECHNICAL, 'Développeur', 'Description.', 'code', 0);
        $group = Uuid::v7();

        $entry->attachToTranslationGroup($group);

        self::assertTrue($group->equals($entry->getTranslationGroup()));
    }

    /**
     * Détacher ne remet pas le groupe à `null` — la colonne est NOT NULL : elle
     * en reçoit un neuf, ce qui isole l'entrée de ses anciennes traductions.
     */
    public function testDetachFromTranslationGroupGivesAFreshGroup(): void
    {
        $entry = new AboutMeCard(Locale::FR, AboutMeCardCategory::TECHNICAL, 'Développeur', 'Description.', 'code', 0);
        $previous = $entry->getTranslationGroup();

        $entry->detachFromTranslationGroup();

        self::assertInstanceOf(UuidV7::class, $entry->getTranslationGroup());
        self::assertFalse($previous->equals($entry->getTranslationGroup()));
    }

    /**
     * Spec 0004 D5 : la clé d'ordre d'un contenu localisé est son groupe, pas
     * son id — c'est ce qui fait qu'un déplacement suit le contenu dans toutes
     * les langues.
     */
    public function testOrderingKeyIsTheTranslationGroupAndNotTheId(): void
    {
        $entry = new AboutMeCard(Locale::FR, AboutMeCardCategory::TECHNICAL, 'Développeur', 'Description.', 'code', 0);

        self::assertSame($entry->getTranslationGroup()->toRfc4122(), $entry->orderingKey());
        self::assertNotSame($entry->getId()->toRfc4122(), $entry->orderingKey());
    }

    public function testMoveToPositionWritesThePosition(): void
    {
        $entry = new AboutMeCard(Locale::FR, AboutMeCardCategory::TECHNICAL, 'Développeur', 'Description.', 'code', 0);

        $entry->moveToPosition(4);

        self::assertSame(4, $entry->getPosition());
    }
}
