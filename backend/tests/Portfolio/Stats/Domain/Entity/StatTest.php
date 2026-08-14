<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Stats\Domain\Entity;

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Portfolio\Stats\Domain\Entity\Stat;
use PHPUnit\Framework\TestCase;

final class StatTest extends TestCase
{
    public function testConstructAssignsAllFields(): void
    {
        $stat = new Stat(Locale::FR, '+50K', 'Lignes de code', 'code', 0);

        self::assertSame(Locale::FR, $stat->getLocale());
        self::assertSame('+50K', $stat->getValue());
        self::assertSame('Lignes de code', $stat->getLabel());
        self::assertSame('code', $stat->getIconKey());
        self::assertSame(0, $stat->getPosition());
        self::assertNull($stat->getId());
    }

    public function testUpdateChangesEverythingExceptLocale(): void
    {
        $stat = new Stat(Locale::EN, '+50K', 'Lines of code', 'code', 0);

        $stat->update('+60K', 'Lines of code (updated)', 'box', 1);

        self::assertSame(Locale::EN, $stat->getLocale());
        self::assertSame('+60K', $stat->getValue());
        self::assertSame('Lines of code (updated)', $stat->getLabel());
        self::assertSame('box', $stat->getIconKey());
        self::assertSame(1, $stat->getPosition());
    }
}
