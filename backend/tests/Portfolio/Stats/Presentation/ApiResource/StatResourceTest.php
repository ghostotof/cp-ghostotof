<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Stats\Presentation\ApiResource;

use App\Portfolio\Stats\Application\StatAdministratorInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Couvre GET /api/stats/{locale} : endpoint public (aucune restriction dans
 * access_control, cf. config/packages/security.yaml), en miroir de
 * ExperienceTechnologyResourceTest.
 */
final class StatResourceTest extends WebTestCase
{
    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('DELETE FROM stat');
        parent::tearDown();
    }

    public function testAnonymousRequestReturnsStatsForRequestedLocaleOrderedByPosition(): void
    {
        $client = self::createClient();
        $administrator = $client->getContainer()->get(StatAdministratorInterface::class);

        $administrator->create(Locale::FR, '+50K', 'Lignes de code', 'code', 1);
        $administrator->create(Locale::FR, '10+', 'Technologies maîtrisées', 'box', 0);
        $administrator->create(Locale::EN, '+50K', 'Lines of code', 'code', 0);

        $client->request('GET', '/api/stats/fr');

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertCount(2, $payload);
        self::assertSame(['10+', '+50K'], array_column($payload, 'value'));
        self::assertArrayNotHasKey('id', $payload[0]);
        self::assertArrayNotHasKey('locale', $payload[0]);
        self::assertArrayNotHasKey('position', $payload[0]);
    }

    public function testUnknownLocaleReturns404(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/stats/de');

        self::assertResponseStatusCodeSame(404);
    }
}
