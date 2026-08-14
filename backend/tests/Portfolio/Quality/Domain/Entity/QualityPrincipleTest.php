<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Quality\Domain\Entity;

use App\Portfolio\Quality\Domain\Entity\QualityPrinciple;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use PHPUnit\Framework\TestCase;

final class QualityPrincipleTest extends TestCase
{
    public function testConstructAssignsAllFields(): void
    {
        $principle = new QualityPrinciple(Locale::FR, 'DDD', 'Modélisation du domaine métier.', 'boxes', 0);

        self::assertSame(Locale::FR, $principle->getLocale());
        self::assertSame('DDD', $principle->getTitle());
        self::assertSame('Modélisation du domaine métier.', $principle->getDescription());
        self::assertSame('boxes', $principle->getIconKey());
        self::assertSame(0, $principle->getPosition());
        self::assertNull($principle->getId());
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
