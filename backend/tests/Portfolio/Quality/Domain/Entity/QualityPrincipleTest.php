<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Quality\Domain\Entity;

use App\Portfolio\Quality\Domain\Entity\QualityPrinciple;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use PHPUnit\Framework\TestCase;
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

        $principle->update('SOLID', 'Solid foundations.', 'columns-3', 1);

        self::assertSame(Locale::EN, $principle->getLocale());
        self::assertSame('SOLID', $principle->getTitle());
        self::assertSame('Solid foundations.', $principle->getDescription());
        self::assertSame('columns-3', $principle->getIconKey());
        self::assertSame(1, $principle->getPosition());
    }
}
