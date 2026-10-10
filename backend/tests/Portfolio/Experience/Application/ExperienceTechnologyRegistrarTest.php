<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Experience\Application;

use App\Portfolio\Experience\Application\ExperienceTechnologyRegistrar;
use App\Portfolio\Experience\Domain\Entity\ExperienceTechnology;
use App\Portfolio\Experience\Domain\Exception\ExperienceTechnologyAlreadyExistsException;
use App\Portfolio\Experience\Domain\Repository\ExperienceTechnologyRepositoryInterface;
use App\Portfolio\Experience\Domain\ValueObject\ExperienceYears;
use App\Portfolio\Experience\Domain\ValueObject\TechnologyName;
use PHPUnit\Framework\TestCase;

final class ExperienceTechnologyRegistrarTest extends TestCase
{
    public function testRegisterPersistsTechnology(): void
    {
        $repository = $this->createMock(ExperienceTechnologyRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findOneByName')
            ->with('PHP')
            ->willReturn(null);
        $repository->expects(self::once())
            ->method('save')
            ->with(self::isInstanceOf(ExperienceTechnology::class));

        $registrar = new ExperienceTechnologyRegistrar($repository);

        $technology = $registrar->register(TechnologyName::fromString('PHP'), ExperienceYears::fromFloat(13.5), 'php', 'HTML / CSS / JavaScript');

        self::assertSame('PHP', $technology->getName());
        self::assertSame(13.5, $technology->getYears());
        self::assertSame('php', $technology->getIconKey());
        self::assertSame('HTML / CSS / JavaScript', $technology->getRelatedTechnologyName());
    }

    public function testRegisterThrowsWhenNameAlreadyUsed(): void
    {
        $existingTechnology = new ExperienceTechnology(TechnologyName::fromString('PHP'), ExperienceYears::fromFloat(13.5));

        $repository = $this->createMock(ExperienceTechnologyRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneByName')->with('PHP')->willReturn($existingTechnology);
        $repository->expects(self::never())->method('save');

        $registrar = new ExperienceTechnologyRegistrar($repository);

        $this->expectException(ExperienceTechnologyAlreadyExistsException::class);

        $registrar->register(TechnologyName::fromString('PHP'), ExperienceYears::fromFloat(13.5), null, null);
    }
}
