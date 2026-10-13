<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\About\Presentation\Command;

use App\Portfolio\About\Application\AboutSettingsAdministratorInterface;
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
    /**
     * Cartes « moi » du contenu de référence, par catégorie et par locale.
     */
    private const array ME_CARDS_PER_CATEGORY = ['technical' => 3, 'personal' => 3, 'hobby' => 5];

    /**
     * Les cinq textes de section tels qu'édités au backoffice, dans l'ordre
     * des arguments de `AboutSettingsAdministratorInterface::update()`.
     */
    private const array EDITED_SETTINGS = ['Édité 1', 'Édité 2', 'Édité 3', 'Édité 4', 'Édité 5'];

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
     * exclus du décompte, parce qu'ils ne sont jamais purgés : un singleton
     * par locale, créé s'il manque, réécrit avec --force seulement (#568).
     * Les compter ferait tenir pour « peuplée » une base qui n'a pourtant
     * aucune carte, et le peuplement automatique de la préprod n'aurait
     * alors jamais lieu.
     *
     * Ce test le prouve en ne laissant QUE des réglages en base.
     */
    public function testSettingsAloneDoNotCountAsExistingContent(): void
    {
        $this->commandTester()->execute([]);
        $this->removeEveryCard();

        // Les réglages sont restés : si on les comptait, la commande renoncerait.
        $this->commandTester()->execute([]);

        $siteCardRepository = self::getContainer()->get(AboutSiteCardRepositoryInterface::class);
        $meCardRepository = self::getContainer()->get(AboutMeCardRepositoryInterface::class);

        self::assertCount(4, $siteCardRepository->findByLocale(Locale::FR));
        self::assertCount(11, $meCardRepository->findByLocale(Locale::FR));
    }

    /**
     * Issue #568 : sans --force, la commande ne réécrit jamais des réglages
     * qui existent. Toutes les cartes ont été supprimées et les cinq textes
     * de section FR édités au backoffice : la relance du Job de préprod
     * (sans --force) recrée les cartes, mais laisse les textes édités en
     * place, sur la même ligne. Avant #568, elle les remettait au contenu de
     * référence sans rien en dire.
     */
    public function testWithoutForceKeepsEditedSettingsWhenNoCardRemains(): void
    {
        $this->commandTester()->execute([]);
        $this->editFrenchSettings();
        $this->removeEveryCard();
        $settingsIdsBefore = $this->settingsIdsByLocale();

        $tester = $this->commandTester();

        self::assertSame(0, $tester->execute([]));

        foreach (Locale::cases() as $locale) {
            self::assertCount(4, self::getContainer()->get(AboutSiteCardRepositoryInterface::class)->findByLocale($locale));
            self::assertCount(11, self::getContainer()->get(AboutMeCardRepositoryInterface::class)->findByLocale($locale));
        }

        self::assertSame(self::EDITED_SETTINGS, $this->settingsTexts(Locale::FR));
        self::assertSame($settingsIdsBefore, $this->settingsIdsByLocale());
        self::assertStringContainsString('[fr] Réglages conservés', $tester->getDisplay());
        self::assertStringContainsString('[en] Réglages conservés', $tester->getDisplay());
    }

    /**
     * Cas limite de #568 : une locale a perdu sa ligne de réglages (seul du
     * SQL peut la supprimer), l'autre a ses textes édités, aucune carte ne
     * reste. Sans --force, la ligne manquante est créée au contenu de
     * référence — sans elle, `GET /api/about/en` répondrait 404 —, l'autre
     * est conservée telle quelle.
     */
    public function testWithoutForceCreatesOnlyMissingSettings(): void
    {
        $this->commandTester()->execute([]);
        $this->editFrenchSettings();
        $this->removeEveryCard();
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->executeStatement('DELETE FROM about_settings WHERE locale = :locale', ['locale' => Locale::EN->value]);

        $tester = $this->commandTester();

        self::assertSame(0, $tester->execute([]));

        self::assertSame(self::EDITED_SETTINGS, $this->settingsTexts(Locale::FR));
        self::assertSame(
            ['About this site', 'About me', 'Technically', 'Personally', 'Outside of work'],
            $this->settingsTexts(Locale::EN),
        );
        self::assertStringContainsString('[fr] Réglages conservés', $tester->getDisplay());
        self::assertStringContainsString('[en] Réglages créés', $tester->getDisplay());
    }

    /**
     * `--force` (issue #562) : les cartes sont purgées **toutes locales
     * confondues** puis recréées, les réglages sont **mis à jour, jamais
     * purgés**. Une carte EN et une carte « moi » FR manquent, les cinq
     * réglages FR ont été édités au backoffice : tout revient au contenu de
     * référence, sans doublon de carte, et chaque locale garde sa ligne de
     * réglages d'origine (même identifiant).
     *
     * Les positions départagent la purge globale d'une purge langue par
     * langue (spec 0004 D3) : celle-ci laisserait les cartes de l'autre
     * langue en place pendant la création, et la numérotation commencerait
     * après elles au lieu de 0. Les comptes seuls seraient les mêmes.
     */
    public function testForceRebuildsCardsAndUpdatesSettingsInPlace(): void
    {
        $this->commandTester()->execute([]);

        $siteCardRepository = self::getContainer()->get(AboutSiteCardRepositoryInterface::class);
        $meCardRepository = self::getContainer()->get(AboutMeCardRepositoryInterface::class);

        $siteCardRepository->remove($siteCardRepository->findByLocale(Locale::EN)[0]);
        $meCardRepository->remove($meCardRepository->findByLocaleAndCategory(Locale::FR, AboutMeCardCategory::PERSONAL)[0]);
        $this->editFrenchSettings();
        self::assertSame(self::EDITED_SETTINGS, $this->settingsTexts(Locale::FR));

        $settingsIdsBefore = $this->settingsIdsByLocale();

        $tester = $this->commandTester();

        self::assertSame(0, $tester->execute(['--force' => true]));
        self::assertStringContainsString('[fr] Réglages remis au contenu de référence', $tester->getDisplay());

        foreach (Locale::cases() as $locale) {
            self::assertSame(
                [0, 1, 2, 3],
                array_map(static fn (AboutSiteCard $card): int => $card->getPosition(), $siteCardRepository->findByLocale($locale)),
                $locale->value.' / cartes site',
            );

            foreach (self::ME_CARDS_PER_CATEGORY as $category => $count) {
                self::assertSame(
                    range(0, $count - 1),
                    array_map(
                        static fn (AboutMeCard $card): int => $card->getPosition(),
                        $meCardRepository->findByLocaleAndCategory($locale, AboutMeCardCategory::from($category)),
                    ),
                    $locale->value.' / '.$category,
                );
            }
        }

        self::assertSame(
            ['À propos de ce site', 'À propos de moi', 'Techniquement', 'Humainement', 'En dehors du travail'],
            $this->settingsTexts(Locale::FR),
        );
        self::assertSame($settingsIdsBefore, $this->settingsIdsByLocale());
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
                \sprintf('[%s] Les cartes de position %d ne partagent pas leur groupe.', $label, $index),
            );
        }
    }

    /**
     * Édite les cinq textes de section FR comme le backoffice le fait, par
     * l'Administrator, avec {@see self::EDITED_SETTINGS}.
     */
    private function editFrenchSettings(): void
    {
        self::getContainer()->get(AboutSettingsAdministratorInterface::class)->update(Locale::FR, ...self::EDITED_SETTINGS);
    }

    /**
     * Supprime toutes les cartes, toutes locales confondues, et laisse les
     * réglages en place : l'état où le garde-fou ne décline plus.
     */
    private function removeEveryCard(): void
    {
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
    }

    /**
     * Les cinq textes de section d'une locale, lus en SQL : la commande et le
     * test partagent l'EntityManager, et une instance déjà chargée ne dirait
     * pas ce qui a été écrit en base.
     *
     * @return list<string>
     */
    private function settingsTexts(Locale $locale): array
    {
        $row = self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchAssociative(
            'SELECT site_eyebrow, me_eyebrow, technical_subtitle, personal_subtitle, hobbies_subtitle FROM about_settings WHERE locale = :locale',
            ['locale' => $locale->value],
        );

        self::assertIsArray($row, $locale->value.' : aucune ligne de réglages.');

        return array_map(static fn (mixed $text): string => \is_string($text) ? $text : '', array_values($row));
    }

    /**
     * Lu en SQL, pas par le dépôt, pour comparer les identifiants tels qu'en
     * base : une purge suivie d'une recréation des réglages les changerait.
     * (Un doublon par locale est impossible, l'index unique
     * `uniq_about_settings_locale` le refuserait dans la commande.)
     *
     * @return array<string, list<string>>
     */
    private function settingsIdsByLocale(): array
    {
        $rows = self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchAllAssociative('SELECT locale, id FROM about_settings ORDER BY locale, id');

        $ids = [];

        foreach ($rows as $row) {
            \assert(\is_string($row['locale']) && \is_string($row['id']));
            $ids[$row['locale']][] = $row['id'];
        }

        return $ids;
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
