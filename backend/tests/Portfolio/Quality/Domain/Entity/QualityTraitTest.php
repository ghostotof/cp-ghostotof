<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Quality\Domain\Entity;

use App\Portfolio\Quality\Domain\Entity\QualityTrait as QualityTraitEntity;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;

final class QualityTraitTest extends TestCase
{
    /**
     * Spec 0003 D1 : l'identite est posee par le constructeur, pas par le
     * flush. Une entite construite est donc deja identifiable, comparable et
     * testable sans base de donnees.
     */
    public function testANewTraitIsIdentifiedByAUuidV7BeforeAnyPersistence(): void
    {
        $trait = new QualityTraitEntity(Locale::FR, 'Architecture propre', 0);

        self::assertInstanceOf(UuidV7::class, $trait->getId());
    }

    /**
     * Pin le v7 et non le v4 : deux constructions successives doivent donner
     * des identifiants distincts et croissants, sur quoi repose l'ordre de
     * repli `ORDER BY id` du repository.
     */
    public function testTwoTraitsBuiltInSequenceGetDistinctIncreasingIds(): void
    {
        $first = new QualityTraitEntity(Locale::FR, 'Architecture propre', 0);
        $second = new QualityTraitEntity(Locale::FR, 'Maintenabilité', 1);

        self::assertNotSame($first->getId()->toRfc4122(), $second->getId()->toRfc4122());
        self::assertLessThan($second->getId()->toRfc4122(), $first->getId()->toRfc4122());
    }

    public function testConstructAssignsAllFields(): void
    {
        $trait = new QualityTraitEntity(Locale::FR, 'Architecture propre', 0);

        self::assertSame(Locale::FR, $trait->getLocale());
        self::assertSame('Architecture propre', $trait->getLabel());
        self::assertSame(0, $trait->getPosition());
    }

    public function testUpdateChangesEverythingExceptLocale(): void
    {
        $trait = new QualityTraitEntity(Locale::EN, 'Clean architecture', 0);

        $trait->update('Maintainability', 1);

        self::assertSame(Locale::EN, $trait->getLocale());
        self::assertSame('Maintainability', $trait->getLabel());
        self::assertSame(1, $trait->getPosition());
    }
}
