<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Watch\Presentation\ApiResource;

use App\Portfolio\Watch\Domain\Entity\WatchSnapshot;
use App\Portfolio\Watch\Domain\Repository\WatchSnapshotRepositoryInterface;
use App\Portfolio\Watch\Domain\ValueObject\SnapshotSourceStatus;
use App\Portfolio\Watch\Domain\ValueObject\WatchSnapshotType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Couvre GET /api/watch. Route publique : c'est du contenu de démonstration,
 * sans donnée personnelle identifiante (objectif n°9 — cf. l'entrée
 * correspondante dans ApiRouteExposureTest).
 */
final class WatchResourceTest extends WebTestCase
{
    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)
            ->getConnection()
            ->executeStatement('DELETE FROM watch_snapshot');
        parent::tearDown();
    }

    private function givenSnapshot(KernelBrowser $client): void
    {
        $repository = $client->getContainer()->get(WatchSnapshotRepositoryInterface::class);
        $repository->save(new WatchSnapshot(
            WatchSnapshotType::RELEASE_CYCLES,
            ['products' => [[
                'slug' => 'php',
                'label' => 'PHP',
                'version' => '8.5.9',
                'status' => 'supported',
                'cycle' => '8.5',
                'endOfActiveSupportFrom' => '2027-12-31',
                'eolFrom' => '2029-12-31',
                'latestVersion' => '8.5.10',
                'hasNewerPatch' => true,
                'documentationUrl' => 'https://endoflife.date/php',
            ]]],
            new \DateTimeImmutable('2026-09-07 04:41:00', new \DateTimeZone('UTC')),
            SnapshotSourceStatus::OK,
        ));
    }

    /**
     * Un snapshot de vulnérabilités tel que le rafraîchisseur l'écrit : avec
     * tout son détail, celui-là même que la réponse publique ne doit pas
     * laisser passer.
     */
    private function givenVulnerabilitySnapshot(KernelBrowser $client): void
    {
        $repository = $client->getContainer()->get(WatchSnapshotRepositoryInterface::class);
        $repository->save(new WatchSnapshot(
            WatchSnapshotType::VULNERABILITIES,
            [
                'packagesScanned' => 84,
                'vulnerabilities' => [[
                    'id' => 'GHSA-h7vf-5wrv-9fhv',
                    'aliases' => ['CVE-2026-0001'],
                    'summary' => 'Une faille sérieuse',
                    'severity' => 'MODERATE',
                    'package' => ['ecosystem' => 'Packagist', 'name' => 'symfony/http-kernel', 'version' => '4.0.0'],
                    'fixedIn' => '4.4.50',
                ]],
            ],
            new \DateTimeImmutable('2026-09-07 04:41:00', new \DateTimeZone('UTC')),
            SnapshotSourceStatus::OK,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(KernelBrowser $client): array
    {
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    public function testAnonymousRequestIsAllowedAndReturnsTheStoredSnapshot(): void
    {
        $client = self::createClient();
        $this->givenSnapshot($client);

        $client->request('GET', '/api/watch');

        self::assertResponseIsSuccessful();

        $payload = $this->decode($client);
        /** @var array<string, mixed> $releaseCycles */
        $releaseCycles = $payload['releaseCycles'];
        self::assertIsArray($releaseCycles['products']);
        self::assertCount(1, $releaseCycles['products']);
        self::assertSame('2026-09-07T04:41:00+00:00', $releaseCycles['refreshedAt']);
        self::assertSame('ok', $releaseCycles['sourceStatus']);
    }

    /**
     * La réponse est identique pour tout le monde et ne bouge qu'une fois par
     * jour : la garder en `no-cache, private` faisait traverser PHP et Postgres
     * à chaque visiteur pour rien.
     *
     * La borne supérieure est ce que ce test protège vraiment. `freshness` est
     * calculé à la lecture et le frontend s'y fie sans le recalculer : une durée
     * de cache trop longue afficherait « donnée fraîche » alors que le
     * rafraîchissement a cessé — soit un mensonge sur précisément ce que cette
     * page prétend rendre visible.
     */
    public function testThePublicResponseIsShareableButBriefly(): void
    {
        $client = self::createClient();
        $this->givenSnapshot($client);

        $client->request('GET', '/api/watch');

        $cacheControl = $client->getResponse()->headers->get('Cache-Control') ?? '';

        self::assertStringContainsString('public', $cacheControl);
        self::assertStringNotContainsString('private', $cacheControl);

        $maxAge = $client->getResponse()->getMaxAge();

        self::assertNotNull($maxAge);
        self::assertGreaterThan(0, $maxAge);
        // C'est le plafond qui porte la garantie, pas la valeur exacte : régler
        // 300 sur 600 reste légitime, passer à la journée ne l'est pas. Figer la
        // borne plutôt que le réglage laisse le second échouer sans que le
        // premier ait à toucher au test.
        self::assertLessThanOrEqual(900, $maxAge);
    }

    /**
     * Le pendant du test précédent, et le seul des deux qui touche à la
     * sécurité : `public` sur une réponse réservée à ROLE_SUPER autoriserait un
     * cache partagé à la resservir à quelqu'un d'autre. Le détail des
     * vulnérabilités ne doit donc jamais devenir cachable publiquement, même par
     * héritage d'un défaut posé ailleurs.
     */
    public function testTheRoleSuperDetailIsNeverPubliclyCacheable(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/backoffice/watch/vulnerabilities');

        $cacheControl = $client->getResponse()->headers->get('Cache-Control') ?? '';

        self::assertStringNotContainsString('public', $cacheControl);
        self::assertStringNotContainsString('s-maxage', $cacheControl);
    }

    /**
     * Sur une base neuve, aucun rafraîchissement n'a encore eu lieu. La réponse
     * doit rester exploitable — jamais 404, jamais 500, et surtout pas d'appel
     * sortant de secours qui remettrait le fournisseur dans le chemin de rendu.
     */
    public function testAnEmptyStateIsServedAsAValidResponse(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/watch');

        self::assertResponseIsSuccessful();

        $payload = $this->decode($client);
        /** @var array<string, mixed> $releaseCycles */
        $releaseCycles = $payload['releaseCycles'];
        self::assertSame([], $releaseCycles['products']);
        // Présentes et explicitement nulles, jamais élidées : le client
        // distingue « jamais rafraîchi » sans avoir à interpréter une absence.
        self::assertArrayHasKey('refreshedAt', $releaseCycles);
        self::assertArrayHasKey('sourceStatus', $releaseCycles);
        self::assertNull($releaseCycles['refreshedAt']);
        self::assertNull($releaseCycles['sourceStatus']);
    }

    /**
     * ⚠️ GARDE-FOU DE LA DÉCISION D4 — À NE JAMAIS ASSOUPLIR.
     *
     * Le snapshot contient le détail complet des vulnérabilités : identifiants,
     * paquets touchés, versions vulnérables, versions correctives. La réponse
     * anonyme, elle, n'en expose qu'un décompte.
     *
     * Publier ce détail reviendrait à tendre au premier scanner venu la carte
     * des faiblesses de ce site en production. Le compte invité ROLE_USER ne
     * suffirait pas davantage : il est partagé et ses identifiants circulent,
     * ce qui en fait l'équivalent du public dès qu'il s'agit d'une surface
     * d'attaque.
     *
     * Ce test cherche l'ABSENCE de champs, ce qui est inhabituel et
     * délibéré : ApiRouteExposureTest prouve qu'une route est protégée, jamais
     * qu'une route publique ne laisse pas fuir un champ de trop. C'est la seule
     * chose qui empêche une évolution distraite de publier tout cela.
     */
    public function testTheAnonymousResponseNeverLeaksVulnerabilityDetails(): void
    {
        $client = self::createClient();
        $this->givenVulnerabilitySnapshot($client);

        $client->request('GET', '/api/watch');

        self::assertResponseIsSuccessful();

        $body = (string) $client->getResponse()->getContent();

        foreach (['GHSA-h7vf-5wrv-9fhv', 'CVE-2026-0001', 'symfony/http-kernel', '4.4.50', 'Une faille'] as $secret) {
            self::assertStringNotContainsString($secret, $body, sprintf(
                'La réponse publique expose « %s », qui relève du seul backoffice (décision D4).',
                $secret,
            ));
        }

        $payload = $this->decode($client);
        /** @var array<string, mixed> $vulnerabilities */
        $vulnerabilities = $payload['vulnerabilities'];

        // Le décompte, lui, est bien là : c'est tout l'intérêt de la page.
        self::assertSame(1, $vulnerabilities['affectedCount']);
        self::assertSame(84, $vulnerabilities['packagesScanned']);

        foreach (['id', 'aliases', 'summary', 'severity', 'package', 'fixedIn', 'vulnerabilities'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $vulnerabilities, sprintf(
                'Le champ « %s » n\'a rien à faire dans la réponse publique (décision D4).',
                $forbidden,
            ));
        }
    }

    /**
     * Aucune analyse n'a jamais abouti : la page doit pouvoir le dire, plutôt
     * que d'afficher un « 0 vulnérabilité » que personne n'a vérifié.
     */
    public function testAnUnscannedInstallationIsNotReportedAsHealthy(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/watch');

        self::assertResponseIsSuccessful();

        $payload = $this->decode($client);
        /** @var array<string, mixed> $vulnerabilities */
        $vulnerabilities = $payload['vulnerabilities'];

        self::assertArrayHasKey('packagesScanned', $vulnerabilities);
        self::assertNull($vulnerabilities['packagesScanned']);
        self::assertNull($vulnerabilities['checkedAt']);
    }

    /**
     * Décision D5, vérifiée plutôt que déclarée : le client HTTP est remplacé
     * par un mock qui échoue au moindre appel. Si un jour quelqu'un ajoute un
     * appel sortant dans le chemin de la requête — un « rafraîchissement
     * paresseux » qui paraîtrait pratique — ce test tombe.
     */
    public function testServingThePageEmitsNoOutgoingRequest(): void
    {
        $client = self::createClient();
        $client->getContainer()->set('http_client', new MockHttpClient(
            static fn (): never => throw new \LogicException(
                'Aucun appel sortant ne doit être émis pendant une requête de visiteur.',
            ),
        ));
        $this->givenSnapshot($client);

        $client->request('GET', '/api/watch');

        self::assertResponseIsSuccessful();
    }
}
