<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Experience\Application;

use App\Portfolio\Experience\Application\ExperienceTechnologyAdministrator;
use App\Portfolio\Experience\Domain\Entity\ExperienceTechnology;
use App\Portfolio\Experience\Domain\Exception\ExperienceTechnologyAlreadyExistsException;
use App\Portfolio\Experience\Domain\Exception\ExperienceTechnologyNotFoundException;
use App\Portfolio\Experience\Domain\Repository\ExperienceTechnologyRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class ExperienceTechnologyAdministratorTest extends TestCase
{
    public function testUpdateReplacesPropertiesAndSaves(): void
    {
        $technology = $this->technologyWithId('PHP', 1);

        $repository = $this->createMock(ExperienceTechnologyRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with(1)->willReturn($technology);
        $repository->expects(self::once())->method('findOneByName')->with('Symfony')->willReturn(null);
        $repository->expects(self::once())->method('save')->with($technology);

        $administrator = new ExperienceTechnologyAdministrator($repository);

        $updated = $administrator->update(1, 'Symfony', 9.5, 'symfony', null);

        self::assertSame('Symfony', $updated->getName());
        self::assertSame(9.5, $updated->getYears());
    }

    public function testUpdateThrowsWhenIdUnknown(): void
    {
        $repository = self::createStub(ExperienceTechnologyRepositoryInterface::class);
        $repository->method('findOneById')->willReturn(null);

        $administrator = new ExperienceTechnologyAdministrator($repository);

        $this->expectException(ExperienceTechnologyNotFoundException::class);

        $administrator->update(404, 'Symfony', 9.5, null, null);
    }

    public function testUpdateThrowsWhenNameCollidesWithAnotherTechnology(): void
    {
        $technology = $this->technologyWithId('PHP', 1);
        $otherTechnology = $this->technologyWithId('Symfony', 2);

        $repository = self::createStub(ExperienceTechnologyRepositoryInterface::class);
        $repository->method('findOneById')->willReturn($technology);
        $repository->method('findOneByName')->willReturn($otherTechnology);

        $administrator = new ExperienceTechnologyAdministrator($repository);

        $this->expectException(ExperienceTechnologyAlreadyExistsException::class);

        $administrator->update(1, 'Symfony', 9.5, null, null);
    }

    public function testUpdateAllowsKeepingTheSameNameOnTheSameTechnology(): void
    {
        $technology = $this->technologyWithId('PHP', 1);

        $repository = self::createStub(ExperienceTechnologyRepositoryInterface::class);
        $repository->method('findOneById')->willReturn($technology);
        $repository->method('findOneByName')->willReturn($technology);

        $administrator = new ExperienceTechnologyAdministrator($repository);

        $updated = $administrator->update(1, 'PHP', 14.0, null, null);

        self::assertSame(14.0, $updated->getYears());
    }

    public function testDeleteRemovesTechnology(): void
    {
        $technology = $this->technologyWithId('PHP', 1);

        $repository = $this->createMock(ExperienceTechnologyRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with(1)->willReturn($technology);
        $repository->expects(self::once())->method('remove')->with($technology);

        $administrator = new ExperienceTechnologyAdministrator($repository);

        $administrator->delete(1);
    }

    public function testDeleteThrowsWhenIdUnknown(): void
    {
        $repository = self::createStub(ExperienceTechnologyRepositoryInterface::class);
        $repository->method('findOneById')->willReturn(null);

        $administrator = new ExperienceTechnologyAdministrator($repository);

        $this->expectException(ExperienceTechnologyNotFoundException::class);

        $administrator->delete(404);
    }

    private function technologyWithId(string $name, int $id): ExperienceTechnology
    {
        $technology = new ExperienceTechnology($name, 1.0);

        $reflection = new \ReflectionProperty(ExperienceTechnology::class, 'id');
        $reflection->setValue($technology, $id);

        return $technology;
    }
}
