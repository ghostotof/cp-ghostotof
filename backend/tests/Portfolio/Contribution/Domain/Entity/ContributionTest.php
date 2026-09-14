<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Contribution\Domain\Entity;

use App\Portfolio\Contribution\Domain\Entity\Contribution;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

final class ContributionTest extends TestCase
{
    private function contribution(): Contribution
    {
        return new Contribution(
            Locale::FR,
            'Retry de transport, re-prompt de validation',
            'symfony/ai',
            'Issue #1688',
            'https://github.com/symfony/ai/issues/1688',
            'Deux opérations sous un seul mot.',
            "Premier paragraphe.\n\nSecond paragraphe.",
            0,
        );
    }

    /**
     * Spec 0003 D1 : l'identite est posee par le constructeur, pas par le
     * flush. Une entite construite est donc deja identifiable, comparable et
     * testable sans base de donnees.
     */
    public function testANewContributionIsIdentifiedByAUuidV7BeforeAnyPersistence(): void
    {
        self::assertInstanceOf(UuidV7::class, $this->contribution()->getId());
    }

    /**
     * Pin le v7 et non le v4 : deux constructions successives doivent donner
     * des identifiants distincts et croissants, sur quoi repose l'ordre de
     * repli `ORDER BY id` du repository.
     */
    public function testTwoContributionsBuiltInSequenceGetDistinctIncreasingIds(): void
    {
        $first = $this->contribution();
        $second = $this->contribution();

        self::assertNotSame($first->getId()->toRfc4122(), $second->getId()->toRfc4122());
        self::assertLessThan($second->getId()->toRfc4122(), $first->getId()->toRfc4122());
    }

    public function testConstructorSetsAllProperties(): void
    {
        $contribution = $this->contribution();

        self::assertSame(Locale::FR, $contribution->getLocale());
        self::assertSame('Retry de transport, re-prompt de validation', $contribution->getTitle());
        self::assertSame('symfony/ai', $contribution->getProject());
        self::assertSame('Issue #1688', $contribution->getReference());
        self::assertSame('https://github.com/symfony/ai/issues/1688', $contribution->getUrl());
        self::assertSame('Deux opérations sous un seul mot.', $contribution->getSummary());
        self::assertSame(0, $contribution->getPosition());
    }

    /**
     * Le corps est stocké tel qu'il a été saisi, sauts de ligne compris : c'est
     * la présentation qui le découpe en paragraphes. Normaliser ici ferait
     * perdre la seule structure dont dispose ce champ.
     */
    public function testBodyKeepsItsBlankLineSeparators(): void
    {
        self::assertSame("Premier paragraphe.\n\nSecond paragraphe.", $this->contribution()->getBody());
    }

    public function testUpdateReplacesEveryMutablePropertyButNotTheLocale(): void
    {
        $contribution = $this->contribution();

        $contribution->update(
            'Nouveau titre',
            'symfony/language-tools',
            'Issue #56',
            'https://github.com/symfony/language-tools/issues/56',
            'Nouveau chapeau.',
            'Nouveau corps.',
            3,
        );

        self::assertSame('Nouveau titre', $contribution->getTitle());
        self::assertSame('symfony/language-tools', $contribution->getProject());
        self::assertSame('Issue #56', $contribution->getReference());
        self::assertSame('https://github.com/symfony/language-tools/issues/56', $contribution->getUrl());
        self::assertSame('Nouveau chapeau.', $contribution->getSummary());
        self::assertSame('Nouveau corps.', $contribution->getBody());
        self::assertSame(3, $contribution->getPosition());
        // La locale n'est pas modifiable : changer la langue d'une contribution
        // revient à en créer une autre, pas à éditer celle-ci.
        self::assertSame(Locale::FR, $contribution->getLocale());
    }

    /**
     * Spec 0004 D1 : le groupe de traduction est un identifiant partagé, pas
     * une entité. Une entrée construite sans groupe en reçoit un neuf — elle
     * est donc toujours dans un groupe, fût-il d'une seule langue, ce qui
     * permet à `translation_group` d'être NOT NULL.
     */
    public function testAnEntryBuiltWithoutAGroupGetsAFreshTranslationGroup(): void
    {
        $first = $this->contribution();
        $second = $this->contribution();

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

        $entry = new Contribution(
            Locale::EN,
            'Transport retry, validation re-prompt',
            'symfony/ai',
            'Issue #1688',
            'https://github.com/symfony/ai/issues/1688',
            'Two operations under a single word.',
            "First paragraph.\n\nSecond paragraph.",
            0,
            $group,
        );

        self::assertTrue($group->equals($entry->getTranslationGroup()));
    }

    public function testAttachToTranslationGroupLinksTheEntryToAnExistingGroup(): void
    {
        $entry = $this->contribution();
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
        $entry = $this->contribution();
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
        $entry = $this->contribution();

        self::assertSame($entry->getTranslationGroup()->toRfc4122(), $entry->orderingKey());
        self::assertNotSame($entry->getId()->toRfc4122(), $entry->orderingKey());
    }

    public function testMoveToPositionWritesThePosition(): void
    {
        $entry = $this->contribution();

        $entry->moveToPosition(4);

        self::assertSame(4, $entry->getPosition());
    }
}
