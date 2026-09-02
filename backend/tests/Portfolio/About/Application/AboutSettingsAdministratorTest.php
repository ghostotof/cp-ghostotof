<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\About\Application;

use App\Portfolio\About\Application\AboutSettingsAdministrator;
use App\Portfolio\About\Domain\Entity\AboutSettings;
use App\Portfolio\About\Domain\Exception\AboutSettingsNotFoundException;
use App\Portfolio\About\Domain\Repository\AboutSettingsRepositoryInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use PHPUnit\Framework\TestCase;

final class AboutSettingsAdministratorTest extends TestCase
{
    public function testUpdateChangesExistingSettings(): void
    {
        $settings = new AboutSettings(Locale::FR, 'Site', 'Moi', 'Technique', 'Perso', 'Loisirs');

        $repository = $this->createMock(AboutSettingsRepositoryInterface::class);
        $repository->expects(self::once())->method('findByLocale')->with(Locale::FR)->willReturn($settings);
        $repository->expects(self::once())->method('save')->with($settings);

        $administrator = new AboutSettingsAdministrator($repository);

        $updated = $administrator->update(Locale::FR, 'New site', 'New moi', 'New technique', 'New perso', 'New loisirs');

        self::assertSame('New site', $updated->getSiteEyebrow());
    }

    public function testUpdateThrowsWhenLocaleHasNoSettingsYet(): void
    {
        $repository = self::createStub(AboutSettingsRepositoryInterface::class);
        $repository->method('findByLocale')->willReturn(null);

        $administrator = new AboutSettingsAdministrator($repository);

        $this->expectException(AboutSettingsNotFoundException::class);

        $administrator->update(Locale::EN, 'a', 'b', 'c', 'd', 'e');
    }
}
