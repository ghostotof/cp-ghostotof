<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\AnonymousCv\Domain\Entity;

use App\Portfolio\AnonymousCv\Domain\Entity\AnonymousCvSection;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

final class AnonymousCvSectionTest extends TestCase
{
    private function section(): AnonymousCvSection
    {
        return new AnonymousCvSection(
            Locale::FR,
            'Backend PHP / Symfony',
            'Symfony 7, Doctrine ORM, API Platform, Messenger',
            12,
            "Conception d'une API multi-tenant servie à plusieurs milliers d'utilisateurs.\n\nMigration d'un monolithe vers une architecture en contextes bornés.",
            0,
        );
    }

    /**
     * Spec 0003 D1 : l'identite est posee par le constructeur, pas par le
     * flush. Une entite construite est donc deja identifiable, comparable et
     * testable sans base de donnees.
     */
    public function testANewSectionIsIdentifiedByAUuidV7BeforeAnyPersistence(): void
    {
        self::assertInstanceOf(UuidV7::class, $this->section()->getId());
    }

    /**
     * Pin le v7 et non le v4 : deux constructions successives doivent donner
     * des identifiants distincts et croissants, sur quoi repose l'ordre de
     * repli `ORDER BY id` du repository.
     */
    public function testTwoSectionsBuiltInSequenceGetDistinctIncreasingIds(): void
    {
        $first = $this->section();
        $second = $this->section();

        self::assertNotSame($first->getId()->toRfc4122(), $second->getId()->toRfc4122());
        self::assertLessThan($second->getId()->toRfc4122(), $first->getId()->toRfc4122());
    }

    public function testConstructorSetsAllProperties(): void
    {
        $section = $this->section();

        self::assertSame(Locale::FR, $section->getLocale());
        self::assertSame('Backend PHP / Symfony', $section->getTitle());
        self::assertSame('Symfony 7, Doctrine ORM, API Platform, Messenger', $section->getSkills());
        self::assertSame(12, $section->getYearsOfExperience());
        self::assertStringStartsWith("Conception d'une API multi-tenant", $section->getAchievements());
        self::assertSame(0, $section->getPosition());
    }

    public function testUpdateReplacesEveryMutablePropertyButNotTheLocale(): void
    {
        $section = $this->section();

        $section->update('Nouveau domaine', 'Nouvelles compétences', 3, 'Nouvelles réalisations.');

        self::assertSame('Nouveau domaine', $section->getTitle());
        self::assertSame('Nouvelles compétences', $section->getSkills());
        self::assertSame(3, $section->getYearsOfExperience());
        self::assertSame('Nouvelles réalisations.', $section->getAchievements());
        // Spec 0004 D3 : `update()` ne touche plus à la position — elle ne se
        // saisit pas, seuls un rattachement à un groupe et l'endpoint d'ordre
        // l'écrivent.
        self::assertSame(0, $section->getPosition());
        // La locale n'est pas modifiable : changer la langue d'une section
        // revient à en créer une autre (même choix que CaseStudy::update()).
        self::assertSame(Locale::FR, $section->getLocale());
    }

    /**
     * Spec 0004 D1 : le groupe de traduction est un identifiant partagé, pas
     * une entité. Une entrée construite sans groupe en reçoit un neuf — elle
     * est donc toujours dans un groupe, fût-il d'une seule langue, ce qui
     * permet à `translation_group` d'être NOT NULL.
     */
    public function testAnEntryBuiltWithoutAGroupGetsAFreshTranslationGroup(): void
    {
        $first = $this->section();
        $second = $this->section();

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

        $entry = new AnonymousCvSection(
            Locale::EN,
            'Backend PHP / Symfony',
            'Symfony 7, Doctrine ORM, API Platform, Messenger',
            12,
            'Design of a multi-tenant API.',
            0,
            $group,
        );

        self::assertTrue($group->equals($entry->getTranslationGroup()));
    }

    public function testAttachToTranslationGroupLinksTheEntryToAnExistingGroup(): void
    {
        $entry = $this->section();
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
        $entry = $this->section();
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
        $entry = $this->section();

        self::assertSame($entry->getTranslationGroup()->toRfc4122(), $entry->orderingKey());
        self::assertNotSame($entry->getId()->toRfc4122(), $entry->orderingKey());
    }

    public function testMoveToPositionWritesThePosition(): void
    {
        $entry = $this->section();

        $entry->moveToPosition(4);

        self::assertSame(4, $entry->getPosition());
    }
}
