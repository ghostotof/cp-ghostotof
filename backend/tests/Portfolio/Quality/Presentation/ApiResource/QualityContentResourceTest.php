<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Quality\Presentation\ApiResource;

use App\Portfolio\Quality\Application\QualityPrincipleAdministratorInterface;
use App\Portfolio\Quality\Application\QualityTraitAdministratorInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Couvre GET /api/quality/{locale} : endpoint public (aucune restriction
 * dans access_control, cf. config/packages/security.yaml), en miroir de
 * StatResourceTest.
 */
final class QualityContentResourceTest extends WebTestCase
{
    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('DELETE FROM quality_principle');
        $connection->executeStatement('DELETE FROM quality_trait');
        parent::tearDown();
    }

    public function testAnonymousRequestReturnsPrinciplesAndTraitsForRequestedLocale(): void
    {
        $client = self::createClient();
        $principleAdministrator = $client->getContainer()->get(QualityPrincipleAdministratorInterface::class);
        $traitAdministrator = $client->getContainer()->get(QualityTraitAdministratorInterface::class);

        $principleAdministrator->create(Locale::FR, 'SOLID', 'Des bases solides.', 'columns-3', 1);
        $principleAdministrator->create(Locale::FR, 'DDD', 'Modélisation du domaine.', 'boxes', 0);
        $principleAdministrator->create(Locale::EN, 'DDD', 'Domain modeling.', 'boxes', 0);
        $traitAdministrator->create(Locale::FR, 'Architecture propre', 0);
        $traitAdministrator->create(Locale::EN, 'Clean architecture', 0);

        $client->request('GET', '/api/quality/fr');

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame(['DDD', 'SOLID'], array_column($payload['principles'], 'title'));
        self::assertArrayNotHasKey('id', $payload['principles'][0]);
        self::assertArrayNotHasKey('locale', $payload['principles'][0]);
        self::assertSame(['Architecture propre'], array_column($payload['traits'], 'label'));
    }

    public function testUnknownLocaleReturns404(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/quality/de');

        self::assertResponseStatusCodeSame(404);
    }
}
