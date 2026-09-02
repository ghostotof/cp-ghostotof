<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\About\Application;

use App\Portfolio\About\Application\AboutSiteCardAdministrator;
use App\Portfolio\About\Domain\Entity\AboutSiteCard;
use App\Portfolio\About\Domain\Exception\AboutSiteCardNotFoundException;
use App\Portfolio\About\Domain\Repository\AboutSiteCardRepositoryInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use PHPUnit\Framework\TestCase;

final class AboutSiteCardAdministratorTest extends TestCase
{
    public function testCreatePersistsCard(): void
    {
        $repository = $this->createMock(AboutSiteCardRepositoryInterface::class);
        $repository->expects(self::once())->method('save')->with(self::isInstanceOf(AboutSiteCard::class));

        $administrator = new AboutSiteCardAdministrator($repository);

        $card = $administrator->create(Locale::FR, 'Architecture', 'Description.', 'layers', 0);

        self::assertSame('Architecture', $card->getTitle());
    }

    public function testUpdateChangesExistingCard(): void
    {
        $card = new AboutSiteCard(Locale::FR, 'Architecture', 'Description.', 'layers', 0);

        $repository = $this->createMock(AboutSiteCardRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with(1)->willReturn($card);
        $repository->expects(self::once())->method('save')->with($card);

        $administrator = new AboutSiteCardAdministrator($repository);

        $updated = $administrator->update(1, 'Stack', 'New description.', 'server', 1);

        self::assertSame('Stack', $updated->getTitle());
    }

    public function testUpdateThrowsWhenCardNotFound(): void
    {
        $repository = self::createStub(AboutSiteCardRepositoryInterface::class);
        $repository->method('findOneById')->willReturn(null);

        $administrator = new AboutSiteCardAdministrator($repository);

        $this->expectException(AboutSiteCardNotFoundException::class);

        $administrator->update(404, 'x', 'x', 'x', 0);
    }

    public function testDeleteRemovesCard(): void
    {
        $card = new AboutSiteCard(Locale::FR, 'Architecture', 'Description.', 'layers', 0);

        $repository = $this->createMock(AboutSiteCardRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with(1)->willReturn($card);
        $repository->expects(self::once())->method('remove')->with($card);

        $administrator = new AboutSiteCardAdministrator($repository);

        $administrator->delete(1);
    }

    public function testDeleteThrowsWhenCardNotFound(): void
    {
        $repository = self::createStub(AboutSiteCardRepositoryInterface::class);
        $repository->method('findOneById')->willReturn(null);

        $administrator = new AboutSiteCardAdministrator($repository);

        $this->expectException(AboutSiteCardNotFoundException::class);

        $administrator->delete(404);
    }
}
