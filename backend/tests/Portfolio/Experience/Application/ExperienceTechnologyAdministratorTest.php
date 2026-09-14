<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Experience\Application;

use App\Portfolio\Experience\Application\ExperienceTechnologyAdministrator;
use App\Portfolio\Experience\Domain\Entity\ExperienceTechnology;
use App\Portfolio\Experience\Domain\Exception\ExperienceTechnologyAlreadyExistsException;
use App\Portfolio\Experience\Domain\Exception\ExperienceTechnologyNotFoundException;
use App\Portfolio\Experience\Domain\Repository\ExperienceTechnologyRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ExperienceTechnologyAdministratorTest extends TestCase
{
    public function testUpdateReplacesPropertiesAndSaves(): void
    {
        $technology = new ExperienceTechnology('PHP', 1.0);

        $repository = $this->createMock(ExperienceTechnologyRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with($technology->getId())->willReturn($technology);
        $repository->expects(self::once())->method('findOneByName')->with('Symfony')->willReturn(null);
        $repository->expects(self::once())->method('save')->with($technology);

        $administrator = new ExperienceTechnologyAdministrator($repository);

        $updated = $administrator->update($technology->getId(), 'Symfony', 9.5, 'symfony', null);

        self::assertSame('Symfony', $updated->getName());
        self::assertSame(9.5, $updated->getYears());
    }

    public function testUpdateThrowsWhenIdUnknown(): void
    {
        $repository = self::createStub(ExperienceTechnologyRepositoryInterface::class);
        $repository->method('findOneById')->willReturn(null);

        $administrator = new ExperienceTechnologyAdministrator($repository);

        $this->expectException(ExperienceTechnologyNotFoundException::class);

        $administrator->update(Uuid::v7(), 'Symfony', 9.5, null, null);
    }

    public function testUpdateThrowsWhenNameCollidesWithAnotherTechnology(): void
    {
        $technology = new ExperienceTechnology('PHP', 1.0);
        $otherTechnology = new ExperienceTechnology('Symfony', 1.0);

        $repository = self::createStub(ExperienceTechnologyRepositoryInterface::class);
        $repository->method('findOneById')->willReturn($technology);
        $repository->method('findOneByName')->willReturn($otherTechnology);

        $administrator = new ExperienceTechnologyAdministrator($repository);

        $this->expectException(ExperienceTechnologyAlreadyExistsException::class);

        $administrator->update($technology->getId(), 'Symfony', 9.5, null, null);
    }

    /**
     * L'id vient de l'URL, donc d'un `Uuid` fraîchement reconstruit, jamais la
     * même instance que celui porté par l'entité : la comparaison doit se
     * faire par valeur (`equals()`), pas par identité (`!==`), faute de quoi
     * le contrôle d'unicité refuserait toute mise à jour, y compris quand le
     * nom ne change pas.
     */
    public function testUpdateAllowsKeepingTheSameNameOnTheSameTechnology(): void
    {
        $technology = new ExperienceTechnology('PHP', 1.0);
        $sameIdFromTheUrl = Uuid::fromString($technology->getId()->toRfc4122());

        $repository = self::createStub(ExperienceTechnologyRepositoryInterface::class);
        $repository->method('findOneById')->willReturn($technology);
        $repository->method('findOneByName')->willReturn($technology);

        $administrator = new ExperienceTechnologyAdministrator($repository);

        $updated = $administrator->update($sameIdFromTheUrl, 'PHP', 14.0, null, null);

        self::assertSame(14.0, $updated->getYears());
    }

    public function testDeleteRemovesTechnology(): void
    {
        $technology = new ExperienceTechnology('PHP', 1.0);

        $repository = $this->createMock(ExperienceTechnologyRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with($technology->getId())->willReturn($technology);
        $repository->expects(self::once())->method('remove')->with($technology);

        $administrator = new ExperienceTechnologyAdministrator($repository);

        $administrator->delete($technology->getId());
    }

    public function testDeleteThrowsWhenIdUnknown(): void
    {
        $repository = self::createStub(ExperienceTechnologyRepositoryInterface::class);
        $repository->method('findOneById')->willReturn(null);

        $administrator = new ExperienceTechnologyAdministrator($repository);

        $this->expectException(ExperienceTechnologyNotFoundException::class);

        $administrator->delete(Uuid::v7());
    }
}
