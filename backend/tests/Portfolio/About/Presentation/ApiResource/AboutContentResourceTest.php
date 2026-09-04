<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\About\Presentation\ApiResource;

use App\Portfolio\About\Application\AboutMeCardAdministratorInterface;
use App\Portfolio\About\Application\AboutSiteCardAdministratorInterface;
use App\Portfolio\About\Domain\Repository\AboutSettingsRepositoryInterface;
use App\Portfolio\About\Domain\Entity\AboutSettings;
use App\Portfolio\About\Domain\ValueObject\AboutMeCardCategory;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Couvre GET /api/about/{locale} : endpoint public (aucune restriction dans
 * access_control, cf. config/packages/security.yaml), en miroir de
 * QualityContentResourceTest.
 */
final class AboutContentResourceTest extends WebTestCase
{
    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('DELETE FROM about_site_card');
        $connection->executeStatement('DELETE FROM about_me_card');
        $connection->executeStatement('DELETE FROM about_settings');
        parent::tearDown();
    }

    public function testAnonymousRequestReturnsFullContentForRequestedLocale(): void
    {
        $client = self::createClient();
        $container = $client->getContainer();

        $container->get(AboutSettingsRepositoryInterface::class)->save(
            new AboutSettings(Locale::FR, 'À propos de ce site', 'À propos de moi', 'Techniquement', 'Humainement', 'En dehors du travail'),
        );

        $siteCardAdministrator = $container->get(AboutSiteCardAdministratorInterface::class);
        $siteCardAdministrator->create(Locale::FR, 'Architecture', 'Description.', 'layers', 1);
        $siteCardAdministrator->create(Locale::FR, 'Stack technique', 'Description.', 'server', 0);

        $meCardAdministrator = $container->get(AboutMeCardAdministratorInterface::class);
        $meCardAdministrator->create(Locale::FR, AboutMeCardCategory::TECHNICAL, 'Développeur', 'Description.', 'code', 0);
        $meCardAdministrator->create(Locale::FR, AboutMeCardCategory::PERSONAL, 'Curieux', 'Description.', 'lightbulb', 0);
        $meCardAdministrator->create(Locale::FR, AboutMeCardCategory::HOBBY, 'Musique', 'Description.', 'guitar', 0);

        $client->request('GET', '/api/about/fr');

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame('À propos de ce site', $payload['site']['eyebrow']);
        self::assertSame(['Stack technique', 'Architecture'], array_column($payload['site']['cards'], 'title'));

        self::assertSame('À propos de moi', $payload['me']['eyebrow']);
        self::assertSame('Techniquement', $payload['me']['technicalSubtitle']);
        self::assertSame(['Développeur'], array_column($payload['me']['technicalCards'], 'title'));
        self::assertSame('Humainement', $payload['me']['personalSubtitle']);
        self::assertSame(['Curieux'], array_column($payload['me']['personalCards'], 'title'));
        // Point d'audit C3 : le filtrage par authentification a été écarté —
        // les trois volets, "hobbies" compris, sont servis à un appelant
        // anonyme. Cette assertion est le garde-fou de cette décision.
        self::assertSame('En dehors du travail', $payload['me']['hobbiesSubtitle']);
        self::assertSame(['Musique'], array_column($payload['me']['hobbiesCards'], 'title'));
    }

    public function testUnseededLocaleReturns404(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/about/fr');

        self::assertResponseStatusCodeSame(404);
    }

    public function testUnknownLocaleReturns404(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/about/de');

        self::assertResponseStatusCodeSame(404);
    }
}
