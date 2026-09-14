<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\About\Application;

use App\Portfolio\About\Application\AboutMeCardAdministrator;
use App\Portfolio\About\Domain\Entity\AboutMeCard;
use App\Portfolio\About\Domain\Exception\AboutMeCardNotFoundException;
use App\Portfolio\About\Domain\Repository\AboutMeCardRepositoryInterface;
use App\Portfolio\About\Domain\ValueObject\AboutMeCardCategory;
use App\Portfolio\Shared\Domain\Service\ContentPlacement;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class AboutMeCardAdministratorTest extends TestCase
{
    public function testCreatePersistsCard(): void
    {
        $repository = $this->createMock(AboutMeCardRepositoryInterface::class);
        $repository->expects(self::once())->method('save')->with(self::isInstanceOf(AboutMeCard::class));

        $administrator = new AboutMeCardAdministrator($repository, new ContentPlacement());

        $card = $administrator->create(Locale::FR, AboutMeCardCategory::TECHNICAL, 'Développeur', 'Description.', 'code');

        self::assertSame('Développeur', $card->getTitle());
        self::assertSame(AboutMeCardCategory::TECHNICAL, $card->getCategory());
    }

    public function testUpdateChangesExistingCard(): void
    {
        $card = new AboutMeCard(Locale::FR, AboutMeCardCategory::HOBBY, 'Musique', 'Description.', 'guitar', 0);

        $repository = $this->createMock(AboutMeCardRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with($card->getId())->willReturn($card);
        $repository->expects(self::once())->method('save')->with($card);

        $administrator = new AboutMeCardAdministrator($repository, new ContentPlacement());

        $updated = $administrator->update($card->getId(), 'Moto', 'New description.', 'motorbike', null);

        self::assertSame('Moto', $updated->getTitle());
        self::assertSame(AboutMeCardCategory::HOBBY, $updated->getCategory());
    }

    public function testUpdateThrowsWhenCardNotFound(): void
    {
        $repository = self::createStub(AboutMeCardRepositoryInterface::class);
        $repository->method('findOneById')->willReturn(null);

        $administrator = new AboutMeCardAdministrator($repository, new ContentPlacement());

        $this->expectException(AboutMeCardNotFoundException::class);

        $administrator->update(Uuid::v7(), 'x', 'x', 'x', null);
    }

    public function testDeleteRemovesCard(): void
    {
        $card = new AboutMeCard(Locale::FR, AboutMeCardCategory::PERSONAL, 'Curieux', 'Description.', 'lightbulb', 0);

        $repository = $this->createMock(AboutMeCardRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with($card->getId())->willReturn($card);
        $repository->expects(self::once())->method('remove')->with($card);

        $administrator = new AboutMeCardAdministrator($repository, new ContentPlacement());

        $administrator->delete($card->getId());
    }

    public function testDeleteThrowsWhenCardNotFound(): void
    {
        $repository = self::createStub(AboutMeCardRepositoryInterface::class);
        $repository->method('findOneById')->willReturn(null);

        $administrator = new AboutMeCardAdministrator($repository, new ContentPlacement());

        $this->expectException(AboutMeCardNotFoundException::class);

        $administrator->delete(Uuid::v7());
    }
}
