<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Experience\Domain\Entity;

use App\Portfolio\Experience\Domain\Entity\ExperienceTechnology;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;

final class ExperienceTechnologyTest extends TestCase
{
    /**
     * Spec 0003 D1 : l'identite est posee par le constructeur, pas par le
     * flush. Une entite construite est donc deja identifiable, comparable et
     * testable sans base de donnees.
     */
    public function testANewTechnologyIsIdentifiedByAUuidV7BeforeAnyPersistence(): void
    {
        $technology = new ExperienceTechnology('PHP', 13.5);

        self::assertInstanceOf(UuidV7::class, $technology->getId());
    }

    /**
     * Pin le v7 et non le v4 : deux constructions successives doivent donner
     * des identifiants distincts et croissants, sur quoi repose l'ordre de
     * repli `ORDER BY id` du repository.
     */
    public function testTwoTechnologiesBuiltInSequenceGetDistinctIncreasingIds(): void
    {
        $first = new ExperienceTechnology('PHP', 13.5);
        $second = new ExperienceTechnology('Symfony', 9.5);

        self::assertNotSame($first->getId()->toRfc4122(), $second->getId()->toRfc4122());
        self::assertLessThan($second->getId()->toRfc4122(), $first->getId()->toRfc4122());
    }

    public function testConstructorSetsAllProperties(): void
    {
        $technology = new ExperienceTechnology('PHP', 13.5, 'php', 'HTML / CSS / JavaScript');

        self::assertSame('PHP', $technology->getName());
        self::assertSame(13.5, $technology->getYears());
        self::assertSame('php', $technology->getIconKey());
        self::assertSame('HTML / CSS / JavaScript', $technology->getRelatedTechnologyName());
        self::assertFalse($technology->isSecondary());
    }

    public function testIconKeyAndRelatedTechnologyAreOptional(): void
    {
        $technology = new ExperienceTechnology('MySQL / PostgreSQL / SQL Server', 13.5);

        self::assertNull($technology->getIconKey());
        self::assertNull($technology->getRelatedTechnologyName());
        self::assertFalse($technology->isSecondary());
    }

    /**
     * Le défaut compte : une technologie créée sans précision reste dans le
     * classement chiffré. L'oubli du drapeau ne doit jamais faire disparaître
     * silencieusement une entrée de la page.
     */
    public function testSecondaryIsOptInAndReadBack(): void
    {
        $secondary = new ExperienceTechnology('Python', 0.5, 'python', null, true);

        self::assertTrue($secondary->isSecondary());
    }

    public function testUpdateCanCollapseAndRestoreATechnology(): void
    {
        $technology = new ExperienceTechnology('Python', 0.5, 'python', null, true);

        $technology->update('Python', 0.5, 'python', null, false);
        self::assertFalse($technology->isSecondary());

        $technology->update('Python', 0.5, 'python', null, true);
        self::assertTrue($technology->isSecondary());
    }

    public function testUpdateReplacesAllMutableProperties(): void
    {
        $technology = new ExperienceTechnology('PHP', 13.5, 'php', 'HTML / CSS / JavaScript');

        $technology->update('Symfony', 9.5, 'symfony', null);

        self::assertFalse($technology->isSecondary());
        self::assertSame('Symfony', $technology->getName());
        self::assertSame(9.5, $technology->getYears());
        self::assertSame('symfony', $technology->getIconKey());
        self::assertNull($technology->getRelatedTechnologyName());
    }
}
