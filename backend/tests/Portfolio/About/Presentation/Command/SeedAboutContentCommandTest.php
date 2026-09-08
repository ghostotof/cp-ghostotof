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

    /**
     * Subtilité propre à ce contexte : les réglages (`AboutSettings`) sont
     * exclus du décompte, parce que leur écriture est un upsert et non une
     * purge — ils ne risquent rien. Les compter ferait tenir pour « peuplée »
     * une base qui n'a pourtant aucune carte, et le peuplement automatique de
     * la préprod n'aurait alors jamais lieu.
     *
     * Ce test le prouve en ne laissant QUE des réglages en base.
     */
    public function testSettingsAloneDoNotCountAsExistingContent(): void
    {
        $this->commandTester()->execute([]);

        $siteCardRepository = self::getContainer()->get(AboutSiteCardRepositoryInterface::class);
        $meCardRepository = self::getContainer()->get(AboutMeCardRepositoryInterface::class);

        foreach (Locale::cases() as $locale) {
            foreach ($siteCardRepository->findByLocale($locale) as $card) {
                $siteCardRepository->remove($card);
            }
            foreach ($meCardRepository->findByLocale($locale) as $card) {
                $meCardRepository->remove($card);
            }
        }

        // Les réglages sont restés : si on les comptait, la commande renoncerait.
        $this->commandTester()->execute([]);

        self::assertCount(4, $siteCardRepository->findByLocale(Locale::FR));
        self::assertCount(11, $meCardRepository->findByLocale(Locale::FR));
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
