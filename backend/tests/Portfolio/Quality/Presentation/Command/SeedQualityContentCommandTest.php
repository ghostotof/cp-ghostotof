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
use Symfony\Component\HttpKernel\KernelInterface;

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

        $principleRepository = self::getContainer()->get(QualityPrincipleRepositoryInterface::class);
        $traitRepository = self::getContainer()->get(QualityTraitRepositoryInterface::class);

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

        $principleRepository = self::getContainer()->get(QualityPrincipleRepositoryInterface::class);
        $traitRepository = self::getContainer()->get(QualityTraitRepositoryInterface::class);

        self::assertCount(3, $principleRepository->findByLocale(Locale::FR));
        self::assertCount(7, $traitRepository->findByLocale(Locale::FR));
    }

    /**
     * Le comptage traverse ici **deux dépôts et deux locales** — la forme la
     * plus riche des cinq commandes de peuplement. Un seul principe déjà en
     * base suffit à protéger l'ensemble : c'est ce qui permet de jouer ce seed
     * à chaque déploiement de préprod sans jamais rien écraser.
     */
    public function testItLeavesExistingContentAlone(): void
    {
        $this->commandTester()->execute([]);

        $principleRepository = self::getContainer()->get(QualityPrincipleRepositoryInterface::class);
        $principleRepository->remove($principleRepository->findByLocale(Locale::FR)[0]);

        $tester = $this->commandTester();
        $tester->execute([]);

        // Toujours amputé : la commande a bien renoncé plutôt que de recréer.
        self::assertCount(2, $principleRepository->findByLocale(Locale::FR));
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('déjà en place', $tester->getDisplay());
    }

    public function testForceRebuildsFromReferenceContent(): void
    {
        $this->commandTester()->execute([]);

        $principleRepository = self::getContainer()->get(QualityPrincipleRepositoryInterface::class);
        $principleRepository->remove($principleRepository->findByLocale(Locale::FR)[0]);

        $this->commandTester()->execute(['--force' => true]);

        self::assertCount(3, $principleRepository->findByLocale(Locale::FR));
    }

    private function commandTester(): CommandTester
    {
        \assert(self::$kernel instanceof KernelInterface);
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:quality:seed'));
    }

    private function purge(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('DELETE FROM quality_principle');
        $connection->executeStatement('DELETE FROM quality_trait');
    }
}
