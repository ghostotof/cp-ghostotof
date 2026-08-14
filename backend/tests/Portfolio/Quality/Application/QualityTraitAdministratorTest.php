<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Quality\Application;

use App\Portfolio\Quality\Application\QualityTraitAdministrator;
use App\Portfolio\Quality\Domain\Entity\QualityTrait as QualityTraitEntity;
use App\Portfolio\Quality\Domain\Exception\QualityTraitNotFoundException;
use App\Portfolio\Quality\Domain\Repository\QualityTraitRepositoryInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use PHPUnit\Framework\TestCase;

final class QualityTraitAdministratorTest extends TestCase
{
    public function testCreatePersistsTrait(): void
    {
        $repository = $this->createMock(QualityTraitRepositoryInterface::class);
        $repository->expects(self::once())->method('save')->with(self::isInstanceOf(QualityTraitEntity::class));

        $administrator = new QualityTraitAdministrator($repository);

        $trait = $administrator->create(Locale::FR, 'Architecture propre', 0);

        self::assertSame('Architecture propre', $trait->getLabel());
    }

    public function testUpdateChangesExistingTrait(): void
    {
        $trait = new QualityTraitEntity(Locale::FR, 'Architecture propre', 0);

        $repository = $this->createMock(QualityTraitRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with(1)->willReturn($trait);
        $repository->expects(self::once())->method('save')->with($trait);

        $administrator = new QualityTraitAdministrator($repository);

        $updated = $administrator->update(1, 'Maintenabilité', 1);

        self::assertSame('Maintenabilité', $updated->getLabel());
        self::assertSame(1, $updated->getPosition());
    }

    public function testUpdateThrowsWhenTraitNotFound(): void
    {
        $repository = $this->createStub(QualityTraitRepositoryInterface::class);
        $repository->method('findOneById')->willReturn(null);

        $administrator = new QualityTraitAdministrator($repository);

        $this->expectException(QualityTraitNotFoundException::class);

        $administrator->update(404, 'Label', 0);
    }

    public function testDeleteRemovesTrait(): void
    {
        $trait = new QualityTraitEntity(Locale::FR, 'Architecture propre', 0);

        $repository = $this->createMock(QualityTraitRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with(1)->willReturn($trait);
        $repository->expects(self::once())->method('remove')->with($trait);

        $administrator = new QualityTraitAdministrator($repository);

        $administrator->delete(1);
    }

    public function testDeleteThrowsWhenTraitNotFound(): void
    {
        $repository = $this->createStub(QualityTraitRepositoryInterface::class);
        $repository->method('findOneById')->willReturn(null);

        $administrator = new QualityTraitAdministrator($repository);

        $this->expectException(QualityTraitNotFoundException::class);

        $administrator->delete(404);
    }
}
