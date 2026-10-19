<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Presentation;

use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Mcp\Support\SpeaksMcp;
use App\Tests\Support\RepositoryFiles;
use App\Tests\Support\TestCredentials;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Server\Session\Psr16SessionStore;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\DoctrineDbalAdapter;

/**
 * Le point d'entrée du serveur MCP (spec 0007, tâche 1 ; ADR 0006) : le
 * handshake aboutit, le serveur n'accepte que l'hôte du site, ses sessions
 * vivent en base, et le canal ignore le cookie du site — ni la garde CSRF ne
 * le refuse, ni le firewall ne le lit (ADR 0006 D4).
 */
final class McpEndpointTest extends WebTestCase
{
    use SpeaksMcp;

    private const string TRUSTED_USERNAME = 'mcp.trusted';

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('DELETE FROM cpg_user');
        parent::tearDown();
    }

    public function testInitializeAnswersWithTheServerIdentity(): void
    {
        $client = self::createClient();

        $this->postInitialize($client);

        self::assertResponseIsSuccessful();
        $response = $this->decodeMcpResponse($client);
        self::assertSame('cp-ghostotof', $response['result']['serverInfo']['name']);
        self::assertIsString($response['result']['instructions']);
        self::assertNotSame('', $response['result']['instructions']);
    }

    public function testToolsListAnswersAfterTheHandshake(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $response = $this->mcpCall($client, $sessionId, 'tools/list');

        self::assertIsArray($response['result']['tools']);
    }

    /**
     * Un client ne demande `prompts/list` ou `resources/list` que si le
     * serveur annonce la capacité ; le serveur répond quand même, par une
     * liste vide tant qu'il n'expose ni prompt ni ressource.
     */
    public function testPromptsAndResourcesListsAnswer(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        self::assertSame([], $this->mcpCall($client, $sessionId, 'prompts/list')['result']['prompts']);
        self::assertSame([], $this->mcpCall($client, $sessionId, 'resources/list')['result']['resources']);
    }

    /**
     * Le SDK répond 405 au GET (il n'ouvre pas de flux), ce que l'AI
     * Assistant de PhpStorm prend pour une panne (ADR 0006, conséquences).
     * Le GET ouvre donc un flux SSE vide, qui demande au client d'attendre
     * cinq minutes avant de le rouvrir, et se ferme aussitôt : aucun worker
     * FPM n'est retenu.
     */
    public function testGetOpensAnEmptyEventStreamInsteadOf405(): void
    {
        $client = self::createClient();

        $client->request('GET', self::MCP_ENDPOINT, server: ['HTTP_ACCEPT' => 'text/event-stream']);

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/event-stream', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertSame("retry: 300000\n\n", $client->getResponse()->getContent());
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
    }

    /**
     * Protection contre le rebond DNS : seul l'hôte du site (dérivé
     * d'APP_FRONTEND_BASE_URL, `localhost` en test) est servi.
     */
    public function testAForeignHostIsRefused(): void
    {
        $client = self::createClient();

        $this->postInitialize($client, ['HTTP_HOST' => 'evil.example']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAForeignOriginIsRefused(): void
    {
        $client = self::createClient();

        $this->postInitialize($client, ['HTTP_ORIGIN' => 'https://evil.example']);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * ADR 0006 D4 : `/api/mcp` est exclu de la garde CSRF, ce qui n'est sûr
     * que parce que son firewall n'accepte aucun cookie. Une requête portant
     * le cookie `BEARER` d'un compte de confiance, sans en-tête XSRF, passe
     * donc la garde et n'authentifie personne.
     */
    public function testATrustedBearerCookieIsNeitherRefusedByTheCsrfGuardNorRead(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::TRUSTED_USERNAME, TestCredentials::plainPassword(), [CpgUser::ROLE_TRUSTED]);
        $this->loginAs($client);
        self::assertNotNull($client->getCookieJar()->get('BEARER'));

        $this->postInitialize($client);

        self::assertResponseIsSuccessful();
        self::assertNull(self::getContainer()->get('security.token_storage')->getToken());
    }

    /**
     * ADR 0005 : deux réplicas, un disque en lecture seule. Une session
     * ouverte sur un pod doit être lue par l'autre, donc vivre en base.
     */
    public function testSessionsLiveInTheDatabase(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        // Le conteneur de test sert le pool tel que compilé (sans traçage) :
        // sa classe est celle que la prod instancie.
        self::assertSame(DoctrineDbalAdapter::class, $container->get('cache.mcp_sessions')::class);
        self::assertSame(Psr16SessionStore::class, $container->get('mcp.server.portfolio.session.store')::class);
    }

    /**
     * ADR 0006 D7 : pas de `^` sur `symfony/mcp-*` ni `mcp/*` tant qu'ils
     * sont en 0.x — aucune promesse de compatibilité.
     */
    public function testTheMcpPackagesArePinnedExactly(): void
    {
        $composer = json_decode(RepositoryFiles::read(RepositoryFiles::backend('composer.json')), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        self::assertIsArray($composer['require']);

        foreach ($composer['require'] as $package => $constraint) {
            if (!\is_string($package) || (!str_starts_with($package, 'symfony/mcp-') && !str_starts_with($package, 'mcp/'))) {
                continue;
            }

            self::assertIsString($constraint);
            self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+\z/', $constraint, \sprintf('%s doit être pinné en version exacte.', $package));
        }

        self::assertArrayHasKey('symfony/mcp-bundle', $composer['require']);
        self::assertArrayHasKey('mcp/sdk', $composer['require']);
    }

    /**
     * @param array<string, string> $server
     */
    private function postInitialize(KernelBrowser $client, array $server = []): void
    {
        $client->request('POST', self::MCP_ENDPOINT, server: $server + [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
        ], content: self::jsonBody([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => self::MCP_PROTOCOL_VERSION,
                'capabilities' => ['roots' => ['listChanged' => false]],
                'clientInfo' => ['name' => 'phpunit', 'version' => '1.0.0'],
            ],
        ]));
    }

    private function loginAs(KernelBrowser $client): void
    {
        $client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'fetch'], content: self::jsonBody([
            'username' => self::TRUSTED_USERNAME,
            'password' => TestCredentials::plainPassword(),
        ]));
        self::assertResponseIsSuccessful();
    }
}
