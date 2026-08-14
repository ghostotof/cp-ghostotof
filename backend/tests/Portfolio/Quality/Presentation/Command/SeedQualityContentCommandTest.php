<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Quality\Presentation\Command;

use App\Portfolio\Quality\Domain\Repository\QualityPrincipleRepositoryInterface;
use App\Portfolio\Quality\Domain\Repository\QualityTraitRepositoryInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SeedQualityContentCommandTest extends KernelTestCase
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

    public function testSeedsPrinciplesAndTraitsForBothLocales(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);

        $principleRepository = $this->getContainer()->get(QualityPrincipleRepositoryInterface::class);
        $traitRepository = $this->getContainer()->get(QualityTraitRepositoryInterface::class);

        self::assertCount(3, $principleRepository->findByLocale(Locale::FR));
        self::assertCount(3, $principleRepository->findByLocale(Locale::EN));
        self::assertCount(7, $traitRepository->findByLocale(Locale::FR));
        self::assertCount(7, $traitRepository->findByLocale(Locale::EN));

        $firstFrPrinciple = $principleRepository->findByLocale(Locale::FR)[0];
        self::assertSame('DDD', $firstFrPrinciple->getTitle());
        self::assertSame(0, $firstFrPrinciple->getPosition());
    }

    public function testIsIdempotent(): void
    {
        $tester = $this->commandTester();
        $tester->execute([]);
        $tester->execute([]);

        $principleRepository = $this->getContainer()->get(QualityPrincipleRepositoryInterface::class);
        $traitRepository = $this->getContainer()->get(QualityTraitRepositoryInterface::class);

        self::assertCount(3, $principleRepository->findByLocale(Locale::FR));
        self::assertCount(7, $traitRepository->findByLocale(Locale::FR));
    }

    private function commandTester(): CommandTester
    {
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:quality:seed'));
    }

    private function purge(): void
    {
        $connection = $this->getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('DELETE FROM quality_principle');
        $connection->executeStatement('DELETE FROM quality_trait');
    }
}
