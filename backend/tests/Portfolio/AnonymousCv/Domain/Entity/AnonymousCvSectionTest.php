<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\AnonymousCv\Domain\Entity;

use App\Portfolio\AnonymousCv\Domain\Entity\AnonymousCvSection;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use PHPUnit\Framework\TestCase;
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

        $section->update('Nouveau domaine', 'Nouvelles compétences', 3, 'Nouvelles réalisations.', 2);

        self::assertSame('Nouveau domaine', $section->getTitle());
        self::assertSame('Nouvelles compétences', $section->getSkills());
        self::assertSame(3, $section->getYearsOfExperience());
        self::assertSame('Nouvelles réalisations.', $section->getAchievements());
        self::assertSame(2, $section->getPosition());
        // La locale n'est pas modifiable : changer la langue d'une section
        // revient à en créer une autre (même choix que CaseStudy::update()).
        self::assertSame(Locale::FR, $section->getLocale());
    }
}
