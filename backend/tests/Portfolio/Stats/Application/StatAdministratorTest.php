<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Stats\Application;

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Portfolio\Stats\Application\StatAdministrator;
use App\Portfolio\Stats\Domain\Entity\Stat;
use App\Portfolio\Stats\Domain\Exception\StatNotFoundException;
use App\Portfolio\Stats\Domain\Repository\StatRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class StatAdministratorTest extends TestCase
{
    public function testCreatePersistsStat(): void
    {
        $repository = $this->createMock(StatRepositoryInterface::class);
        $repository->expects(self::once())->method('save')->with(self::isInstanceOf(Stat::class));

        $administrator = new StatAdministrator($repository);

        $stat = $administrator->create(Locale::FR, '+50K', 'Lignes de code', 'code', 0);

        self::assertSame('+50K', $stat->getValue());
    }

    public function testUpdateChangesExistingStat(): void
    {
        $stat = new Stat(Locale::FR, '+50K', 'Lignes de code', 'code', 0);

        $repository = $this->createMock(StatRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with(1)->willReturn($stat);
        $repository->expects(self::once())->method('save')->with($stat);

        $administrator = new StatAdministrator($repository);

        $updated = $administrator->update(1, '+60K', 'Lignes de code (maj)', 'box', 1);

        self::assertSame('+60K', $updated->getValue());
        self::assertSame(1, $updated->getPosition());
    }

    public function testUpdateThrowsWhenStatNotFound(): void
    {
        $repository = $this->createStub(StatRepositoryInterface::class);
        $repository->method('findOneById')->willReturn(null);

        $administrator = new StatAdministrator($repository);

        $this->expectException(StatNotFoundException::class);

        $administrator->update(404, '+60K', 'Label', 'icon', 0);
    }

    public function testDeleteRemovesStat(): void
    {
        $stat = new Stat(Locale::FR, '+50K', 'Lignes de code', 'code', 0);

        $repository = $this->createMock(StatRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneById')->with(1)->willReturn($stat);
        $repository->expects(self::once())->method('remove')->with($stat);

        $administrator = new StatAdministrator($repository);

        $administrator->delete(1);
    }

    public function testDeleteThrowsWhenStatNotFound(): void
    {
        $repository = $this->createStub(StatRepositoryInterface::class);
        $repository->method('findOneById')->willReturn(null);

        $administrator = new StatAdministrator($repository);

        $this->expectException(StatNotFoundException::class);

        $administrator->delete(404);
    }
}
