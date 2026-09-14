<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Contribution\Domain\Entity;

use App\Portfolio\Contribution\Domain\Entity\Contribution;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use PHPUnit\Framework\TestCase;
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
}
