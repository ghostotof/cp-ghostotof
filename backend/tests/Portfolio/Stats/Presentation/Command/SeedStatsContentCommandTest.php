<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Stats\Presentation\Command;

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Portfolio\Stats\Domain\Repository\StatRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SeedStatsContentCommandTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    public function testSeedsStatsForBothLocales(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);

        $statRepository = $this->getContainer()->get(StatRepositoryInterface::class);

        self::assertCount(4, $statRepository->findByLocale(Locale::FR));
        self::assertCount(4, $statRepository->findByLocale(Locale::EN));

        $firstFrStat = $statRepository->findByLocale(Locale::FR)[0];
        self::assertSame('+50K', $firstFrStat->getValue());
        self::assertSame('Lignes de code', $firstFrStat->getLabel());
        self::assertSame(0, $firstFrStat->getPosition());
    }

    public function testIsIdempotent(): void
    {
        $tester = $this->commandTester();
        $tester->execute([]);
        $tester->execute([]);

        $statRepository = $this->getContainer()->get(StatRepositoryInterface::class);

        self::assertCount(4, $statRepository->findByLocale(Locale::FR));
        self::assertCount(4, $statRepository->findByLocale(Locale::EN));
    }

    private function commandTester(): CommandTester
    {
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:stats:seed'));
    }

    private function purge(): void
    {
        $this->getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('DELETE FROM stat');
    }
}
