<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Quality\Application;

use App\Portfolio\Quality\Application\QualityPrincipleAdministrator;
use App\Portfolio\Quality\Domain\Entity\QualityPrinciple;
use App\Portfolio\Quality\Domain\Exception\QualityPrincipleNotFoundException;
use App\Portfolio\Quality\Domain\Repository\QualityPrincipleRepositoryInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use PHPUnit\Framework\TestCase;

final class QualityPrincipleAdministratorTest extends TestCase
{
    public function testCreatePersistsPrinciple(): void
    {
        $repository = $this->createMock(QualityPrincipleRepositoryInterface::class);
        $repository->expects(self::once())->method('save')->with(self::isInstanceOf(QualityPrinciple::class));

        $administrator = new QualityPrincipleAdministrator($repository);

        $principle = $administrator->create(Locale::FR, 'DDD', 'Description.', 'boxes', 0);

        self::assertSame('DDD', $principle->getTitle());
    }

    public function testUpdateChangesExistingPrinciple(): void
    {
        $principle = new QualityPrinciple(Locale::FR, 'DDD', 'Description.', 'boxes', 0);

        $repository = $this->createMock(QualityPrincipleRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with(1)->willReturn($principle);
        $repository->expects(self::once())->method('save')->with($principle);

        $administrator = new QualityPrincipleAdministrator($repository);

        $updated = $administrator->update(1, 'SOLID', 'Description mise à jour.', 'columns-3', 1);

        self::assertSame('SOLID', $updated->getTitle());
        self::assertSame(1, $updated->getPosition());
    }

    public function testUpdateThrowsWhenPrincipleNotFound(): void
    {
        $repository = $this->createStub(QualityPrincipleRepositoryInterface::class);
        $repository->method('findOneById')->willReturn(null);

        $administrator = new QualityPrincipleAdministrator($repository);

        $this->expectException(QualityPrincipleNotFoundException::class);

        $administrator->update(404, 'Titre', 'Description', 'icon', 0);
    }

    public function testDeleteRemovesPrinciple(): void
    {
        $principle = new QualityPrinciple(Locale::FR, 'DDD', 'Description.', 'boxes', 0);

        $repository = $this->createMock(QualityPrincipleRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with(1)->willReturn($principle);
        $repository->expects(self::once())->method('remove')->with($principle);

        $administrator = new QualityPrincipleAdministrator($repository);

        $administrator->delete(1);
    }

    public function testDeleteThrowsWhenPrincipleNotFound(): void
    {
        $repository = $this->createStub(QualityPrincipleRepositoryInterface::class);
        $repository->method('findOneById')->willReturn(null);

        $administrator = new QualityPrincipleAdministrator($repository);

        $this->expectException(QualityPrincipleNotFoundException::class);

        $administrator->delete(404);
    }
}
