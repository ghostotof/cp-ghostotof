<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Presentation;

use App\Mcp\Infrastructure\Http\ServerAnswers;
use App\Mcp\Presentation\Controller\McpEndpointController;
use App\Tests\Mcp\Support\SpeaksMcp;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Symfony\AI\McpBundle\Controller\McpController;
use Symfony\AI\McpBundle\Http\MiddlewareFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * Le GET de `/api/mcp` (relecture de la tâche 2) : il traverse **les mêmes
 * middlewares que le POST** — la protection contre le rebond DNS et le CORS
 * du SDK, construits par le bundle avec la liste `allowed_hosts` —, au lieu
 * d'en recopier les règles avec un seul hôte. Puis il répond comme le
 * transport pour une session : 404 si elle n'existe pas (ou plus), 400 si
 * son identifiant n'est pas un UUID, 406 sans `Accept: text/event-stream`.
 * `HEAD`, que le routeur fait correspondre au GET, reçoit la même réponse
 * sans corps, plus le 405 du SDK.
 */
final class McpEndpointGetTest extends WebTestCase
{
    use SpeaksMcp;

    private const array EVENT_STREAM = ['HTTP_ACCEPT' => 'text/event-stream'];

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        self::forgetMcpSessions(self::getContainer()->get(EntityManagerInterface::class)->getConnection());
        parent::tearDown();
    }

    /**
     * Les règles d'hôte du SDK, sur une liste de plusieurs hôtes : un second
     * hôte du site et un hôte IPv6 entre crochets (port ignoré) passent, un
     * hôte étranger non. Avec un seul hôte recopié, le GET refusait le second
     * hôte que le POST acceptait, et découpait `[::1]:8080` en `[`.
     *
     * @return iterable<string, array{string, int}>
     */
    public static function hostsAgainstASeveralHostList(): iterable
    {
        yield 'premier hôte' => ['localhost', 200];
        yield 'second hôte' => ['www.site.example', 200];
        yield 'IPv6 avec port' => ['[::1]:8080', 200];
        yield 'hôte étranger' => ['evil.example', 403];
    }

    #[DataProvider('hostsAgainstASeveralHostList')]
    public function testTheGetAppliesTheSdkHostRulesToTheWholeConfiguredList(string $host, int $expectedStatus): void
    {
        self::bootKernel();
        $controller = $this->controllerAllowing(['localhost', 'www.site.example', '[::1]']);

        $response = $controller->handle(Request::create('/api/mcp', server: ['HTTP_HOST' => $host] + self::EVENT_STREAM));

        self::assertSame($expectedStatus, $response->getStatusCode());
    }

    /**
     * Une page servie sous le nom du site (son `Origin`) est admise, port
     * ignoré, comme au POST.
     */
    public function testAGetFromThePageOfTheSiteIsServed(): void
    {
        $client = self::createClient();

        $client->request('GET', self::MCP_ENDPOINT, server: ['HTTP_ORIGIN' => 'http://localhost:5173'] + self::EVENT_STREAM);

        self::assertResponseIsSuccessful();
    }

    public function testAGetWithAnUnknownSessionIs404(): void
    {
        $client = self::createClient();

        $client->request('GET', self::MCP_ENDPOINT, server: ['HTTP_MCP_SESSION_ID' => Uuid::v4()->toRfc4122()] + self::EVENT_STREAM);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Une session présente mais illisible — le MAC du cache refusé après une
     * rotation d'APP_SECRET — est morte : 404, le client se réinitialise
     * (#673, point 3, décision du 2026-10-21). `exists()` seul la servait.
     */
    public function testAGetWithAnUnreadableSessionIs404(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();

        // Une valeur sans le MAC d'AuthenticatedCacheMarshaller : la ligne
        // existe, sa lecture échoue.
        self::assertSame(1, $connection->executeStatement(
            "UPDATE cache_items SET item_data = decode('00', 'hex') WHERE item_id LIKE ?",
            ['%mcp-portfolio-'.$sessionId.'%'],
        ));

        $client->request('GET', self::MCP_ENDPOINT, server: ['HTTP_MCP_SESSION_ID' => $sessionId] + self::EVENT_STREAM);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * La règle d'en-tête du SDK (`ProtocolVersionMiddleware`), que le transport
     * applique au trafic de l'ère à poignée de main — un GET sans corps en est
     * —, après les middlewares d'hôte : une révision qu'il ne négocie pas est
     * un 400, la révision sans session comprise (#673, point 3). Absent, l'en-tête
     * vaut 2025-03-26.
     *
     * @return iterable<string, array{string, int}>
     */
    public static function protocolVersionHeaders(): iterable
    {
        yield 'révision à poignée de main' => ['2025-06-18', 200];
        yield 'dernière à poignée de main' => ['2025-11-25', 200];
        yield 'révision inconnue' => ['1999-01-01', 400];
        yield 'révision sans session' => ['2026-07-28', 400];
    }

    #[DataProvider('protocolVersionHeaders')]
    public function testTheGetAppliesTheSdkProtocolVersionRule(string $version, int $expectedStatus): void
    {
        $client = self::createClient();

        $client->request('GET', self::MCP_ENDPOINT, server: ['HTTP_MCP_PROTOCOL_VERSION' => $version] + self::EVENT_STREAM);

        self::assertResponseStatusCodeSame($expectedStatus);
    }

    public function testAGetWithAMalformedSessionIdIs400(): void
    {
        $client = self::createClient();

        $client->request('GET', self::MCP_ENDPOINT, server: ['HTTP_MCP_SESSION_ID' => 'not-a-uuid'] + self::EVENT_STREAM);

        self::assertResponseStatusCodeSame(400);
    }

    /**
     * Un `Mcp-Session-Id` vide — ou « 0 », que PHP lit faux comme lui — vaut
     * absent pour le transport du SDK (`$sessionIdString ? … : null`) : le GET
     * le sert comme un GET sans en-tête, au lieu du 400 d'un UUID invalide
     * (#663, point 1).
     *
     * @return iterable<string, array{string}>
     */
    public static function sessionIdsTheTransportReadsAsAbsent(): iterable
    {
        yield 'vide' => [''];
        yield 'zéro' => ['0'];
    }

    #[DataProvider('sessionIdsTheTransportReadsAsAbsent')]
    public function testAGetWithASessionIdTheTransportReadsAsAbsentIsServed(string $sessionId): void
    {
        $client = self::createClient();

        $client->request('GET', self::MCP_ENDPOINT, server: ['HTTP_MCP_SESSION_ID' => $sessionId] + self::EVENT_STREAM);

        self::assertResponseIsSuccessful();
        self::assertSame("retry: 300000\n\n", $client->getResponse()->getContent());
    }

    public function testAGetWithALiveSessionOpensTheStream(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $client->request('GET', self::MCP_ENDPOINT, server: ['HTTP_MCP_SESSION_ID' => $sessionId] + self::EVENT_STREAM);

        self::assertResponseIsSuccessful();
        self::assertSame("retry: 300000\n\n", $client->getResponse()->getContent());
    }

    public function testAGetThatDoesNotAcceptAnEventStreamIs406(): void
    {
        $client = self::createClient();

        $client->request('GET', self::MCP_ENDPOINT, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(406);
    }

    public function testHeadIsAnsweredLikeTheGetWithoutABody(): void
    {
        $client = self::createClient();

        $client->request('HEAD', self::MCP_ENDPOINT, server: self::EVENT_STREAM);

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/event-stream', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertSame('', $client->getResponse()->getContent());
    }

    /**
     * Le contrôleur, construit avec les services du conteneur et une autre
     * liste d'hôtes : en test, `allowed_hosts` n'en porte qu'un (`localhost`).
     * Le contrôleur du bundle qu'il décore ne sert pas au GET.
     *
     * @param list<string> $allowedHosts
     */
    private function controllerAllowing(array $allowedHosts): McpEndpointController
    {
        $container = self::getContainer();
        $inner = new ReflectionProperty(McpEndpointController::class, 'inner')->getValue($container->get('mcp.server.portfolio.controller'));
        self::assertInstanceOf(McpController::class, $inner);

        return new McpEndpointController(
            $inner,
            new MiddlewareFactory($allowedHosts),
            $container->get('mcp.psr_http_factory'),
            $container->get('mcp.http_foundation_factory'),
            $container->get('mcp.psr17_factory'),
            $container->get('mcp.server.portfolio.session.store'),
            new ServerAnswers(),
        );
    }
}
