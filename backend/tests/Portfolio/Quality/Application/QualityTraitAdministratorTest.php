<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Quality\Application;

use App\Portfolio\Quality\Application\QualityTraitAdministrator;
use App\Portfolio\Quality\Domain\Entity\QualityTrait as QualityTraitEntity;
use App\Portfolio\Quality\Domain\Exception\QualityTraitNotFoundException;
use App\Portfolio\Quality\Domain\Repository\QualityTraitRepositoryInterface;
use App\Portfolio\Shared\Domain\Service\ContentPlacement;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class QualityTraitAdministratorTest extends TestCase
{
    public function testCreatePersistsTrait(): void
    {
        $repository = $this->createMock(QualityTraitRepositoryInterface::class);
        $repository->expects(self::once())->method('save')->with(self::isInstanceOf(QualityTraitEntity::class));

        $administrator = new QualityTraitAdministrator($repository, new ContentPlacement());

        $trait = $administrator->create(Locale::FR, 'Architecture propre');

        self::assertSame('Architecture propre', $trait->getLabel());
    }

    public function testUpdateChangesExistingTrait(): void
    {
        $trait = new QualityTraitEntity(Locale::FR, 'Architecture propre', 0);

        $repository = $this->createMock(QualityTraitRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with($trait->getId())->willReturn($trait);
        $repository->expects(self::once())->method('save')->with($trait);

        $administrator = new QualityTraitAdministrator($repository, new ContentPlacement());

        $updated = $administrator->update($trait->getId(), 'Maintenabilité', null);

        self::assertSame('Maintenabilité', $updated->getLabel());
        // Spec 0004 D3 : `update` ne touche plus à la position — elle ne se
        // saisit pas. Seuls un rattachement à un groupe et l'endpoint d'ordre
        // l'écrivent.
        self::assertSame(0, $updated->getPosition());
    }

    public function testUpdateThrowsWhenTraitNotFound(): void
    {
        $repository = self::createStub(QualityTraitRepositoryInterface::class);
        $repository->method('findOneById')->willReturn(null);

        $administrator = new QualityTraitAdministrator($repository, new ContentPlacement());

        $this->expectException(QualityTraitNotFoundException::class);

        $administrator->update(Uuid::v7(), 'Label', null);
    }

    public function testDeleteRemovesTrait(): void
    {
        $trait = new QualityTraitEntity(Locale::FR, 'Architecture propre', 0);

        $repository = $this->createMock(QualityTraitRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with($trait->getId())->willReturn($trait);
        $repository->expects(self::once())->method('remove')->with($trait);

        $administrator = new QualityTraitAdministrator($repository, new ContentPlacement());

        $administrator->delete($trait->getId());
    }

    public function testDeleteThrowsWhenTraitNotFound(): void
    {
        $repository = self::createStub(QualityTraitRepositoryInterface::class);
        $repository->method('findOneById')->willReturn(null);

        $administrator = new QualityTraitAdministrator($repository, new ContentPlacement());

        $this->expectException(QualityTraitNotFoundException::class);

        $administrator->delete(Uuid::v7());
    }
}
