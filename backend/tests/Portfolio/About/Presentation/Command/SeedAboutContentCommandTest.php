<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\About\Presentation\Command;

use App\Portfolio\About\Domain\Repository\AboutMeCardRepositoryInterface;
use App\Portfolio\About\Domain\Repository\AboutSettingsRepositoryInterface;
use App\Portfolio\About\Domain\Repository\AboutSiteCardRepositoryInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

final class SeedAboutContentCommandTest extends KernelTestCase
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

    public function testSeedsSiteAndMeCardsForBothLocales(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);

        $siteCardRepository = self::getContainer()->get(AboutSiteCardRepositoryInterface::class);
        $meCardRepository = self::getContainer()->get(AboutMeCardRepositoryInterface::class);
        $settingsRepository = self::getContainer()->get(AboutSettingsRepositoryInterface::class);

        self::assertCount(4, $siteCardRepository->findByLocale(Locale::FR));
        self::assertCount(4, $siteCardRepository->findByLocale(Locale::EN));
        self::assertCount(11, $meCardRepository->findByLocale(Locale::FR));

        $frSettings = $settingsRepository->findByLocale(Locale::FR);
        self::assertNotNull($frSettings);
        self::assertSame('À propos de ce site', $frSettings->getSiteEyebrow());

        $firstFrSiteCard = $siteCardRepository->findByLocale(Locale::FR)[0];
        self::assertSame('Architecture', $firstFrSiteCard->getTitle());
        self::assertSame(0, $firstFrSiteCard->getPosition());
    }

    public function testIsIdempotent(): void
    {
        $tester = $this->commandTester();
        $tester->execute([]);
        $tester->execute([]);

        $siteCardRepository = self::getContainer()->get(AboutSiteCardRepositoryInterface::class);
        $meCardRepository = self::getContainer()->get(AboutMeCardRepositoryInterface::class);

        self::assertCount(4, $siteCardRepository->findByLocale(Locale::FR));
        self::assertCount(4, $siteCardRepository->findByLocale(Locale::EN));
        self::assertCount(11, $meCardRepository->findByLocale(Locale::FR));
        self::assertCount(11, $meCardRepository->findByLocale(Locale::EN));
    }

    private function commandTester(): CommandTester
    {
        \assert(self::$kernel instanceof KernelInterface);
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:about:seed'));
    }

    private function purge(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('DELETE FROM about_me_card');
        $connection->executeStatement('DELETE FROM about_site_card');
        $connection->executeStatement('DELETE FROM about_settings');
    }
}
