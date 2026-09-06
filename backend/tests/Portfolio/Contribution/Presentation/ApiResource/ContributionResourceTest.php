<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Contribution\Presentation\ApiResource;

use App\Portfolio\Contribution\Application\ContributionAdministratorInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Couvre GET /api/contributions/{locale}. Cette route doit rester accessible à
 * un client anonyme : c'est exactement le contenu destiné au visiteur arrivé
 * depuis LinkedIn, et il ne porte aucune donnée personnelle identifiante
 * (objectif n°9 — cf. l'entrée correspondante dans ApiRouteExposureTest).
 */
final class ContributionResourceTest extends WebTestCase
{
    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('DELETE FROM contribution');
        parent::tearDown();
    }

    public function testAnonymousRequestIsAllowedAndReturnsContributionsOrderedByPosition(): void
    {
        $client = self::createClient();
        $administrator = $client->getContainer()->get(ContributionAdministratorInterface::class);

        $administrator->create(Locale::FR, 'Deuxième', 'symfony/ai', 'Issue #2', 'https://example.test/2', 'Chapeau 2.', 'Corps 2.', 1);
        $administrator->create(Locale::FR, 'Première', 'symfony/ai', 'Issue #1', 'https://example.test/1', 'Chapeau 1.', 'Corps 1.', 0);

        $client->request('GET', '/api/contributions/fr');

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        self::assertSame(['Première', 'Deuxième'], array_column($payload, 'title'));
    }

    public function testOnlyTheRequestedLocaleIsReturned(): void
    {
        $client = self::createClient();
        $administrator = $client->getContainer()->get(ContributionAdministratorInterface::class);

        $administrator->create(Locale::FR, 'Version française', 'symfony/ai', 'Issue #1', 'https://example.test/1', 'Chapeau.', 'Corps.', 0);
        $administrator->create(Locale::EN, 'English version', 'symfony/ai', 'Issue #1', 'https://example.test/1', 'Lede.', 'Body.', 0);

        $client->request('GET', '/api/contributions/en');

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        self::assertSame(['English version'], array_column($payload, 'title'));
    }

    /**
     * Le contrat public n'expose ni l'id ni la position : ce sont des détails
     * d'administration, sans usage côté visiteur.
     */
    public function testThePublicContractExposesOnlyTheEditorialFields(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(ContributionAdministratorInterface::class)->create(Locale::FR, 'Titre', 'symfony/ai', 'Issue #1', 'https://example.test/1', 'Chapeau.', 'Corps.', 0);

        $client->request('GET', '/api/contributions/fr');

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertArrayHasKey(0, $payload);
        self::assertIsArray($payload[0]);

        self::assertSame(
            ['title', 'project', 'reference', 'url', 'summary', 'body'],
            array_keys($payload[0]),
        );
    }

    /**
     * Locale inconnue : InvalidLocaleException, la seule exception mappée en
     * 404 dans exception_to_status. Surtout pas un 500, ni une liste vide qui
     * laisserait croire que la langue existe mais n'a pas de contenu.
     */
    public function testAnUnknownLocaleIsRejectedWithNotFound(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/contributions/de');

        self::assertResponseStatusCodeSame(404);
    }
}
