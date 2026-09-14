<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Quality\Domain\Entity;

use App\Portfolio\Quality\Domain\Entity\QualityPrinciple;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

final class QualityPrincipleTest extends TestCase
{
    /**
     * Spec 0003 D1 : l'identite est posee par le constructeur, pas par le
     * flush. Une entite construite est donc deja identifiable, comparable et
     * testable sans base de donnees.
     */
    public function testANewPrincipleIsIdentifiedByAUuidV7BeforeAnyPersistence(): void
    {
        $principle = new QualityPrinciple(Locale::FR, 'DDD', 'Modélisation du domaine métier.', 'boxes', 0);

        self::assertInstanceOf(UuidV7::class, $principle->getId());
    }

    /**
     * Pin le v7 et non le v4 : deux constructions successives doivent donner
     * des identifiants distincts et croissants, sur quoi repose l'ordre de
     * repli `ORDER BY id` du repository.
     */
    public function testTwoPrinciplesBuiltInSequenceGetDistinctIncreasingIds(): void
    {
        $first = new QualityPrinciple(Locale::FR, 'DDD', 'Description.', 'boxes', 0);
        $second = new QualityPrinciple(Locale::FR, 'SOLID', 'Description.', 'columns-3', 1);

        self::assertNotSame($first->getId()->toRfc4122(), $second->getId()->toRfc4122());
        self::assertLessThan($second->getId()->toRfc4122(), $first->getId()->toRfc4122());
    }

    public function testConstructAssignsAllFields(): void
    {
        $principle = new QualityPrinciple(Locale::FR, 'DDD', 'Modélisation du domaine métier.', 'boxes', 0);

        self::assertSame(Locale::FR, $principle->getLocale());
        self::assertSame('DDD', $principle->getTitle());
        self::assertSame('Modélisation du domaine métier.', $principle->getDescription());
        self::assertSame('boxes', $principle->getIconKey());
        self::assertSame(0, $principle->getPosition());
    }

    public function testUpdateChangesEverythingExceptLocale(): void
    {
        $principle = new QualityPrinciple(Locale::EN, 'DDD', 'Domain modeling.', 'boxes', 0);

        $principle->update('SOLID', 'Solid foundations.', 'columns-3');

        self::assertSame(Locale::EN, $principle->getLocale());
        self::assertSame('SOLID', $principle->getTitle());
        self::assertSame('Solid foundations.', $principle->getDescription());
        self::assertSame('columns-3', $principle->getIconKey());
        // Spec 0004 D3 : `update()` ne touche plus à la position — elle ne se
        // saisit pas, seuls un rattachement à un groupe et l'endpoint d'ordre
        // l'écrivent.
        self::assertSame(0, $principle->getPosition());
    }

    /**
     * Spec 0004 D1 : le groupe de traduction est un identifiant partagé, pas
     * une entité. Une entrée construite sans groupe en reçoit un neuf — elle
     * est donc toujours dans un groupe, fût-il d'une seule langue, ce qui
     * permet à `translation_group` d'être NOT NULL.
     */
    public function testAnEntryBuiltWithoutAGroupGetsAFreshTranslationGroup(): void
    {
        $first = new QualityPrinciple(Locale::FR, 'DDD', 'Description.', 'boxes', 0);
        $second = new QualityPrinciple(Locale::FR, 'DDD', 'Description.', 'boxes', 0);

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

        $entry = new QualityPrinciple(Locale::EN, 'DDD', 'Description.', 'boxes', 0, $group);

        self::assertTrue($group->equals($entry->getTranslationGroup()));
    }

    public function testAttachToTranslationGroupLinksTheEntryToAnExistingGroup(): void
    {
        $entry = new QualityPrinciple(Locale::FR, 'DDD', 'Description.', 'boxes', 0);
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
        $entry = new QualityPrinciple(Locale::FR, 'DDD', 'Description.', 'boxes', 0);
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
        $entry = new QualityPrinciple(Locale::FR, 'DDD', 'Description.', 'boxes', 0);

        self::assertSame($entry->getTranslationGroup()->toRfc4122(), $entry->orderingKey());
        self::assertNotSame($entry->getId()->toRfc4122(), $entry->orderingKey());
    }

    public function testMoveToPositionWritesThePosition(): void
    {
        $entry = new QualityPrinciple(Locale::FR, 'DDD', 'Description.', 'boxes', 0);

        $entry->moveToPosition(4);

        self::assertSame(4, $entry->getPosition());
    }
}
