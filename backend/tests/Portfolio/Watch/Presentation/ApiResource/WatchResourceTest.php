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
        self::assertIsArray($payload['products']);
        self::assertCount(1, $payload['products']);
        self::assertSame('2026-09-07T04:41:00+00:00', $payload['refreshedAt']);
        self::assertSame('ok', $payload['sourceStatus']);
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
        self::assertSame([], $payload['products']);
        // Présentes et explicitement nulles, jamais élidées : le client
        // distingue « jamais rafraîchi » sans avoir à interpréter une absence.
        self::assertArrayHasKey('refreshedAt', $payload);
        self::assertArrayHasKey('sourceStatus', $payload);
        self::assertNull($payload['refreshedAt']);
        self::assertNull($payload['sourceStatus']);
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
