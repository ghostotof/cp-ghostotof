<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\About\Presentation\Command;

use App\Portfolio\About\Domain\Entity\AboutMeCard;
use App\Portfolio\About\Domain\Entity\AboutSiteCard;
use App\Portfolio\About\Domain\Repository\AboutMeCardRepositoryInterface;
use App\Portfolio\About\Domain\Repository\AboutSettingsRepositoryInterface;
use App\Portfolio\About\Domain\Repository\AboutSiteCardRepositoryInterface;
use App\Portfolio\About\Domain\ValueObject\AboutMeCardCategory;
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

    /**
     * Spec 0004 D1 : ce que le peuplement doit garantir et que rien d'autre ne
     * dit — l'entrée FR et l'entrée EN de même index sont **le même contenu**,
     * elles portent donc le même groupe de traduction. C'est ce lien que la
     * migration a dû déduire sur l'existant ; sur une base neuve, il naît ici.
     *
     * La seconde assertion garde l'autre bord : autant de groupes que
     * d'entrées par locale. Un partage trop large — un seul groupe pour toute
     * la table — satisferait la première et serait tout aussi faux.
     */
    public function testFrenchAndEnglishCardsOfTheSameIndexShareATranslationGroup(): void
    {
        $this->commandTester()->execute([]);

        $siteCardRepository = self::getContainer()->get(AboutSiteCardRepositoryInterface::class);
        $meCardRepository = self::getContainer()->get(AboutMeCardRepositoryInterface::class);

        $this->assertLocalesShareTheirGroups(
            'cartes site',
            $siteCardRepository->findByLocale(Locale::FR),
            $siteCardRepository->findByLocale(Locale::EN),
        );

        // Le périmètre d'ordre des cartes « moi » est la catégorie : c'est donc
        // par catégorie que l'appariement doit se vérifier.
        foreach (AboutMeCardCategory::cases() as $category) {
            $this->assertLocalesShareTheirGroups(
                $category->value,
                $meCardRepository->findByLocaleAndCategory(Locale::FR, $category),
                $meCardRepository->findByLocaleAndCategory(Locale::EN, $category),
            );
        }
    }

    /**
     * Le `$scope` de TranslationGroupIndex sépare les catégories, qui
     * numérotent chacune depuis zéro : la carte technique 0 et la carte loisir
     * 0 ne doivent pas se prétendre traductions l'une de l'autre. Onze cartes
     * par locale, donc onze groupes distincts — et aucun partagé avec les
     * cartes site.
     */
    public function testEachMeCardCategoryGetsItsOwnGroups(): void
    {
        $this->commandTester()->execute([]);

        $meCardGroups = array_map(
            static fn (AboutMeCard $card): string => $card->getTranslationGroup()->toRfc4122(),
            self::getContainer()->get(AboutMeCardRepositoryInterface::class)->findByLocale(Locale::FR),
        );
        $siteCardGroups = array_map(
            static fn (AboutSiteCard $card): string => $card->getTranslationGroup()->toRfc4122(),
            self::getContainer()->get(AboutSiteCardRepositoryInterface::class)->findByLocale(Locale::FR),
        );

        self::assertCount(11, $meCardGroups);
        self::assertSameSize($meCardGroups, array_unique($meCardGroups));
        self::assertSame([], array_intersect($meCardGroups, $siteCardGroups));
    }

    /**
     * @param list<AboutSiteCard>|list<AboutMeCard> $french
     * @param list<AboutSiteCard>|list<AboutMeCard> $english
     */
    private function assertLocalesShareTheirGroups(string $label, array $french, array $english): void
    {
        self::assertNotEmpty($french);
        self::assertSameSize($french, $english);

        foreach ($french as $index => $card) {
            self::assertTrue(
                $card->getTranslationGroup()->equals($english[$index]->getTranslationGroup()),
                sprintf('[%s] Les cartes de position %d ne partagent pas leur groupe.', $label, $index),
            );
        }
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
