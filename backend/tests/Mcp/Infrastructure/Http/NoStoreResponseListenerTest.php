<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Infrastructure\Http;

use App\Tests\Mcp\Support\HoldsAnMcpConnection;
use App\Tests\Mcp\Support\SpeaksMcp;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aucune réponse du point MCP n'est stockée par un cache (#648, relecture de
 * sécurité, B5) : la réponse JSON du SDK ne porte aucun en-tête de cache, et
 * Symfony lui donnait sa valeur par défaut, `no-cache, private` — qui exclut
 * les caches partagés mais laisse un cache privé (un proxy d'entreprise, le
 * cache HTTP d'une bibliothèque cliente) **stocker** la réponse, corpus
 * nominatif compris. Toute réponse de `/api/mcp` porte `no-store`, celles des
 * outils publics aussi : une seule règle pour le point, plutôt qu'un tri par
 * outil qu'un outil de plus oublierait.
 */
final class NoStoreResponseListenerTest extends WebTestCase
{
    use HoldsAnMcpConnection;
    use SpeaksMcp;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->setUpOAuthFlow();
    }

    protected function tearDown(): void
    {
        self::forgetMcpSessions(self::getContainer()->get(EntityManagerInterface::class)->getConnection());
        $this->tearDownOAuthFlow();
        parent::tearDown();
    }

    /** La lecture authentifiée du corpus nominatif. */
    public function testAnAuthenticatedReadOfTheNominativeCorpusIsNotStored(): void
    {
        $bearer = self::bearer($this->holdMcpConnection()->accessToken);
        $sessionId = $this->initializeMcpSession($this->client, $bearer);

        $this->postMcp($this->client, $sessionId, $this->toolCall('get_nominative_corpus'), $bearer);

        self::assertFalse($this->decodeMcpResponse($this->client)['result']['isError'] ?? true, 'Le corpus devait être servi.');
        $this->assertNotStoredResponse();
    }

    /** Un outil public, sans connexion. */
    public function testAnAnonymousAnswerOfAPublicToolIsNotStored(): void
    {
        $sessionId = $this->initializeMcpSession($this->client);

        $this->postMcp($this->client, $sessionId, $this->toolCall('get_contact_info'));

        self::assertFalse($this->decodeMcpResponse($this->client)['result']['isError'] ?? true);
        $this->assertNotStoredResponse();
    }

    /** L'`initialize`, qui porte l'identifiant de session. */
    public function testTheInitializeAnswerIsNotStored(): void
    {
        $this->initializeMcpSession($this->client);

        $this->assertNotStoredResponse();
    }

    /**
     * Le chemin canonique décide, comme les gardes voisines (CanonicalPath,
     * issue #77) : `/api/%6Dcp` est routé vers le serveur MCP.
     */
    public function testAPercentEncodedPathIsTheEndpointToo(): void
    {
        $this->client->request('POST', '/api/%6Dcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_MCP_PROTOCOL_VERSION' => self::MCP_PROTOCOL_VERSION,
        ], content: self::jsonBody(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']));

        $this->assertNotStoredResponse();
    }

    /** Hors du point MCP, rien ne change : la règle est la sienne. */
    public function testAnotherApiRouteKeepsItsOwnCacheControl(): void
    {
        $this->client->request('GET', '/api/about/fr');

        self::assertStringNotContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    private function assertNotStoredResponse(): void
    {
        $response = $this->client->getResponse();
        self::assertNotSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'), 'Cache-Control: '.$response->headers->get('Cache-Control'));
    }

    /**
     * @return array<string, mixed>
     */
    private function toolCall(string $name): array
    {
        return ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => ['locale' => 'fr']]];
    }
}
