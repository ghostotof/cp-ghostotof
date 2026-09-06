<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Experience\Domain\Entity;

use App\Portfolio\Experience\Domain\Entity\ExperienceTechnology;
use PHPUnit\Framework\TestCase;

final class ExperienceTechnologyTest extends TestCase
{
    public function testConstructorSetsAllProperties(): void
    {
        $technology = new ExperienceTechnology('PHP', 13.5, 'php', 'HTML / CSS / JavaScript');

        self::assertNull($technology->getId());
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
