<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\About\Application;

use App\Portfolio\About\Application\AboutMeCardAdministrator;
use App\Portfolio\About\Domain\Entity\AboutMeCard;
use App\Portfolio\About\Domain\Exception\AboutMeCardNotFoundException;
use App\Portfolio\About\Domain\Repository\AboutMeCardRepositoryInterface;
use App\Portfolio\About\Domain\ValueObject\AboutMeCardCategory;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use PHPUnit\Framework\TestCase;

final class AboutMeCardAdministratorTest extends TestCase
{
    public function testCreatePersistsCard(): void
    {
        $repository = $this->createMock(AboutMeCardRepositoryInterface::class);
        $repository->expects(self::once())->method('save')->with(self::isInstanceOf(AboutMeCard::class));

        $administrator = new AboutMeCardAdministrator($repository);

        $card = $administrator->create(Locale::FR, AboutMeCardCategory::TECHNICAL, 'Développeur', 'Description.', 'code', 0);

        self::assertSame('Développeur', $card->getTitle());
        self::assertSame(AboutMeCardCategory::TECHNICAL, $card->getCategory());
    }

    public function testUpdateChangesExistingCard(): void
    {
        $card = new AboutMeCard(Locale::FR, AboutMeCardCategory::HOBBY, 'Musique', 'Description.', 'guitar', 0);

        $repository = $this->createMock(AboutMeCardRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with(1)->willReturn($card);
        $repository->expects(self::once())->method('save')->with($card);

        $administrator = new AboutMeCardAdministrator($repository);

        $updated = $administrator->update(1, 'Moto', 'New description.', 'motorbike', 1);

        self::assertSame('Moto', $updated->getTitle());
        self::assertSame(AboutMeCardCategory::HOBBY, $updated->getCategory());
    }

    public function testUpdateThrowsWhenCardNotFound(): void
    {
        $repository = $this->createStub(AboutMeCardRepositoryInterface::class);
        $repository->method('findOneById')->willReturn(null);

        $administrator = new AboutMeCardAdministrator($repository);

        $this->expectException(AboutMeCardNotFoundException::class);

        $administrator->update(404, 'x', 'x', 'x', 0);
    }

    public function testDeleteRemovesCard(): void
    {
        $card = new AboutMeCard(Locale::FR, AboutMeCardCategory::PERSONAL, 'Curieux', 'Description.', 'lightbulb', 0);

        $repository = $this->createMock(AboutMeCardRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with(1)->willReturn($card);
        $repository->expects(self::once())->method('remove')->with($card);

        $administrator = new AboutMeCardAdministrator($repository);

        $administrator->delete(1);
    }

    public function testDeleteThrowsWhenCardNotFound(): void
    {
        $repository = $this->createStub(AboutMeCardRepositoryInterface::class);
        $repository->method('findOneById')->willReturn(null);

        $administrator = new AboutMeCardAdministrator($repository);

        $this->expectException(AboutMeCardNotFoundException::class);

        $administrator->delete(404);
    }
}
