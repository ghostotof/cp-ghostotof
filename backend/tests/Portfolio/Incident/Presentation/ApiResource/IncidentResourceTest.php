<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Incident\Presentation\ApiResource;

use App\Portfolio\Incident\Application\IncidentAdministratorInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Couvre GET /api/incidents/{locale}. Route publique : c'est du contenu de
 * démonstration sans donnée personnelle identifiante (objectif n°9 — cf.
 * l'entrée correspondante dans ApiRouteExposureTest).
 */
final class IncidentResourceTest extends WebTestCase
{
    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('DELETE FROM incident');
        parent::tearDown();
    }

    public function testAnonymousRequestIsAllowedAndReturnsIncidentsOrderedByPosition(): void
    {
        $client = self::createClient();
        $administrator = $client->getContainer()->get(IncidentAdministratorInterface::class);

        $administrator->create(Locale::FR, 'Deuxième', 'v0.4.0', new \DateTimeImmutable('2026-09-02'), 'I', 'C', 'R', 'Règle 2', 1);
        $administrator->create(Locale::FR, 'Premier', 'v0.5.0', new \DateTimeImmutable('2026-09-03'), 'I', 'C', 'R', 'Règle 1', 0);

        $client->request('GET', '/api/incidents/fr');

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        self::assertSame(['Premier', 'Deuxième'], array_column($payload, 'title'));
    }

    public function testOnlyTheRequestedLocaleIsReturned(): void
    {
        $client = self::createClient();
        $administrator = $client->getContainer()->get(IncidentAdministratorInterface::class);

        $administrator->create(Locale::FR, 'Version française', 'v0.5.0', new \DateTimeImmutable('2026-09-03'), 'I', 'C', 'R', 'Règle', 0);
        $administrator->create(Locale::EN, 'English version', 'v0.5.0', new \DateTimeImmutable('2026-09-03'), 'I', 'C', 'R', 'Rule', 0);

        $client->request('GET', '/api/incidents/en');

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        self::assertSame(['English version'], array_column($payload, 'title'));
    }

    /**
     * Le contrat public expose l'invariant et une date ISO. L'invariant n'est
     * pas optionnel : le frontend le rend dans un encadré dédié, et une
     * absence casserait la promesse éditoriale de la page.
     */
    public function testThePublicContractExposesTheInvariantAndAnIsoDate(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(IncidentAdministratorInterface::class)
            ->create(Locale::FR, 'Titre', 'v0.5.0', new \DateTimeImmutable('2026-09-03'), 'I', 'C', 'R', 'La règle acquise.', 0);

        $client->request('GET', '/api/incidents/fr');

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertArrayHasKey(0, $payload);
        self::assertIsArray($payload[0]);

        self::assertSame(
            ['title', 'version', 'occurredAt', 'impact', 'rootCause', 'resolution', 'invariant'],
            array_keys($payload[0]),
        );
        self::assertSame('La règle acquise.', $payload[0]['invariant']);
        self::assertSame('2026-09-03', $payload[0]['occurredAt']);
    }

    public function testAnUnknownLocaleIsRejectedWithNotFound(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/incidents/de');

        self::assertResponseStatusCodeSame(404);
    }
}
