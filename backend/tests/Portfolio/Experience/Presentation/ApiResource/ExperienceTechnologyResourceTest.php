<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Experience\Presentation\ApiResource;

use App\Portfolio\Experience\Application\ExperienceTechnologyRegistrarInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Couvre GET /api/experience/technologies : contrairement à /api/me et
 * /api/cv (cf. DownloadCvControllerTest), cette route doit rester accessible
 * à un client anonyme (objectif n°9 : aucune donnée personnelle identifiante
 * ici, cf. access_control dans config/packages/security.yaml qui ne la liste
 * pas).
 */
final class ExperienceTechnologyResourceTest extends WebTestCase
{
    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('DELETE FROM experience_technology');
        parent::tearDown();
    }

    public function testAnonymousRequestIsAllowedAndReturnsTechnologiesOrderedByYearsDesc(): void
    {
        $client = self::createClient();
        $registrar = $client->getContainer()->get(ExperienceTechnologyRegistrarInterface::class);

        $registrar->register('Symfony', 9.5, 'symfony', null);
        $registrar->register('PHP', 13.5, 'php', 'HTML / CSS / JavaScript');
        $registrar->register('Docker', 6.5, 'docker', null);

        $client->request('GET', '/api/experience/technologies');

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        $names = array_column($payload, 'name');

        self::assertSame(['PHP', 'Symfony', 'Docker'], $names);
        self::assertSame('php', $payload[0]['iconKey']);
        self::assertSame(['name' => 'HTML / CSS / JavaScript'], $payload[0]['relatedTechnology']);
        self::assertNull($payload[1]['relatedTechnology']);
    }

    /**
     * Le drapeau `secondary` doit traverser toute la chaîne
     * entité → presenter → ressource API Platform. Le frontend s'en sert pour
     * sortir la technologie du classement chiffré : s'il n'était pas
     * sérialisé, toutes les technologies repliées réapparaîtraient dans le
     * classement avec leur durée — précisément ce que ce champ évite.
     */
    public function testSecondaryFlagIsExposedForEveryTechnology(): void
    {
        $client = self::createClient();
        $registrar = $client->getContainer()->get(ExperienceTechnologyRegistrarInterface::class);

        $registrar->register('PHP', 13.5, 'php', null);
        $registrar->register('Python', 0.5, 'python', null, true);

        $client->request('GET', '/api/experience/technologies');

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        /** @var array<string, bool> $secondaryByName */
        $secondaryByName = [];

        self::assertIsArray($payload);
        foreach ($payload as $technology) {
            self::assertIsArray($technology);
            self::assertArrayHasKey('name', $technology);
            self::assertArrayHasKey('secondary', $technology, 'Le champ "secondary" doit être sérialisé, y compris à false.');

            $name = $technology['name'];
            self::assertIsString($name);
            self::assertIsBool($technology['secondary']);

            $secondaryByName[$name] = $technology['secondary'];
        }

        self::assertSame(['PHP' => false, 'Python' => true], $secondaryByName);
    }
}
