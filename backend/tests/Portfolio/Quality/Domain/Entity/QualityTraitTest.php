<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Quality\Domain\Entity;

use App\Portfolio\Quality\Domain\Entity\QualityTrait as QualityTraitEntity;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use PHPUnit\Framework\TestCase;

final class QualityTraitTest extends TestCase
{
    public function testConstructAssignsAllFields(): void
    {
        $trait = new QualityTraitEntity(Locale::FR, 'Architecture propre', 0);

        self::assertSame(Locale::FR, $trait->getLocale());
        self::assertSame('Architecture propre', $trait->getLabel());
        self::assertSame(0, $trait->getPosition());
        self::assertNull($trait->getId());
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
