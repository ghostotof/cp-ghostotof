<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Quality\Application;

use App\Portfolio\Quality\Application\QualityPrincipleAdministrator;
use App\Portfolio\Quality\Domain\Entity\QualityPrinciple;
use App\Portfolio\Quality\Domain\Exception\QualityPrincipleNotFoundException;
use App\Portfolio\Quality\Domain\Repository\QualityPrincipleRepositoryInterface;
use App\Portfolio\Shared\Domain\Service\ContentPlacement;
use App\Portfolio\Shared\Domain\Service\OrderAssigner;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class QualityPrincipleAdministratorTest extends TestCase
{
    public function testCreatePersistsPrinciple(): void
    {
        $repository = $this->createMock(QualityPrincipleRepositoryInterface::class);
        $repository->expects(self::once())->method('save')->with(self::isInstanceOf(QualityPrinciple::class));

        $administrator = new QualityPrincipleAdministrator($repository, new ContentPlacement(), new OrderAssigner());

        $principle = $administrator->create(Locale::FR, 'DDD', 'Description.', 'boxes');

        self::assertSame('DDD', $principle->getTitle());
    }

    public function testUpdateChangesExistingPrinciple(): void
    {
        $principle = new QualityPrinciple(Locale::FR, 'DDD', 'Description.', 'boxes', 0);

        $repository = $this->createMock(QualityPrincipleRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with($principle->getId())->willReturn($principle);
        $repository->expects(self::once())->method('save')->with($principle);

        $administrator = new QualityPrincipleAdministrator($repository, new ContentPlacement(), new OrderAssigner());

        $updated = $administrator->update($principle->getId(), 'SOLID', 'Description mise à jour.', 'columns-3', null);

        self::assertSame('SOLID', $updated->getTitle());
        // Spec 0004 D3 : `update` ne touche plus à la position — elle ne se
        // saisit pas. Seuls un rattachement à un groupe et l'endpoint d'ordre
        // l'écrivent.
        self::assertSame(0, $updated->getPosition());
    }

    public function testUpdateThrowsWhenPrincipleNotFound(): void
    {
        $repository = self::createStub(QualityPrincipleRepositoryInterface::class);
        $repository->method('findOneById')->willReturn(null);

        $administrator = new QualityPrincipleAdministrator($repository, new ContentPlacement(), new OrderAssigner());

        $this->expectException(QualityPrincipleNotFoundException::class);

        $administrator->update(Uuid::v7(), 'Titre', 'Description', 'icon', null);
    }

    public function testDeleteRemovesPrinciple(): void
    {
        $principle = new QualityPrinciple(Locale::FR, 'DDD', 'Description.', 'boxes', 0);

        $repository = $this->createMock(QualityPrincipleRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with($principle->getId())->willReturn($principle);
        $repository->expects(self::once())->method('remove')->with($principle);

        $administrator = new QualityPrincipleAdministrator($repository, new ContentPlacement(), new OrderAssigner());

        $administrator->delete($principle->getId());
    }

    public function testDeleteThrowsWhenPrincipleNotFound(): void
    {
        $repository = self::createStub(QualityPrincipleRepositoryInterface::class);
        $repository->method('findOneById')->willReturn(null);

        $administrator = new QualityPrincipleAdministrator($repository, new ContentPlacement(), new OrderAssigner());

        $this->expectException(QualityPrincipleNotFoundException::class);

        $administrator->delete(Uuid::v7());
    }
}
