<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Presentation;

use App\Mcp\Infrastructure\Http\ServerAnswers;
use App\Mcp\Infrastructure\Log\SdkChannelRedactor;
use App\Mcp\Infrastructure\Session\BoundedSessionStore;
use App\Mcp\Presentation\Controller\McpEndpointController;
use App\Tests\Mcp\Fixtures\UnreachableSimpleCache;
use App\Tests\Mcp\Support\SpeaksMcp;
use App\Tests\Support\ReadsAllChannelsLog;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\SessionStoreException;
use Mcp\Server\Session\Psr16SessionStore;
use Mcp\Server\Session\SessionStoreInterface;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionProperty;
use Symfony\AI\McpBundle\Controller\McpController;
use Symfony\AI\McpBundle\Http\MiddlewareFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Uid\Uuid;

/**
 * Le GET de `/api/mcp` (relecture de la tâche 2) : il traverse **les mêmes
 * middlewares que le POST** — ceux du SDK : sa protection contre le rebond
 * DNS, la seule que le bundle construit avec `allowed_hosts` et
 * `allowed_origins`, et son CORS, laissé à ses défauts (aucune origine
 * autorisée) —, au lieu d'en recopier les règles avec un seul hôte. Puis il répond comme le
 * transport pour une session : 404 si elle n'existe pas (ou plus), 400 si
 * son identifiant n'est pas un UUID, 406 sans `Accept: text/event-stream`,
 * 503 quand le store de sessions est injoignable (#731).
 * `HEAD`, que le routeur fait correspondre au GET, reçoit la même réponse
 * sans corps, plus le 405 du SDK.
 */
final class McpEndpointGetTest extends WebTestCase
{
    use ReadsAllChannelsLog;
    use SpeaksMcp;

    private const array EVENT_STREAM = ['HTTP_ACCEPT' => 'text/event-stream'];

    /** Le corps du 403 que le SDK rend pour un `Origin` qu'il n'admet pas. */
    public const string INVALID_ORIGIN = 'Forbidden: Invalid Origin header.';

    /**
     * Le message du 503 et de sa ligne `error`, recopié du SDK
     * (`Protocol::resolveSession()`) : un oracle à part du contrôleur.
     */
    private const string STORE_UNAVAILABLE = 'Session store is unavailable.';

    /** Le code JSON-RPC d'une erreur interne (`Error::INTERNAL_ERROR` du SDK), recopié de même. */
    private const int INTERNAL_ERROR = -32603;

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
        $container = self::getContainer();
        $controller = $this->controllerWith(['localhost', 'www.site.example', '[::1]'], $container->get('mcp.server.portfolio.session.store'), new NullLogger());

        $response = $controller->handle(Request::create('/api/mcp', server: ['HTTP_HOST' => $host] + self::EVENT_STREAM));

        self::assertSame($expectedStatus, $response->getStatusCode());
    }

    /**
     * Une page du site (son `Origin`, schéma et port compris) est admise par
     * `allowed_origins` de mcp.yaml, comme au POST : en dev et en test le
     * site, sur Vite, n'est pas sur l'origine de l'API.
     */
    public function testAGetFromThePageOfTheSiteIsServed(): void
    {
        $client = self::createClient();

        $client->request('GET', self::MCP_ENDPOINT, server: ['HTTP_ORIGIN' => 'http://localhost:5173'] + self::EVENT_STREAM);

        self::assertResponseIsSuccessful();
    }

    /**
     * L'`Origin` contre le site (R4 de #731). Le SDK le compare **schéma et
     * port compris** à deux choses seulement : l'origine de la requête
     * elle-même (ici `http://localhost`, l'API), toujours admise, et
     * `allowed_origins`, qui porte l'origine du site (`APP_FRONTEND_BASE_URL`,
     * `http://localhost:5173` en test). Tout le reste est un 403 : le même
     * hôte sous un autre schéma ou un autre port (MCP Inspector, sur 6274), une
     * origine opaque (`null` : iframe isolée, `file://`), une page tierce.
     *
     * Un `Origin` **vide** est refusé lui aussi, et non lu comme absent : le
     * middleware teste la présence de l'en-tête (`hasHeader('Origin')`), pas
     * sa valeur, et une valeur vide n'égale ni l'origine de la requête ni une
     * entrée d'`allowed_origins` — tant que celle-ci n'est pas elle-même vide,
     * ce que {@see \App\Tests\Mcp\Infrastructure\Kubernetes\McpAllowedOriginPerEnvironmentTest}
     * exige dans chaque environnement.
     *
     * L'en-tête reçu n'est que mis en minuscules avant la comparaison : la
     * casse est donc indifférente, mais une barre finale — que le SDK retire
     * de la valeur configurée, jamais de l'en-tête — suffit au refus. Le port
     * par défaut écrit en clair n'est admis que pour l'origine de la requête
     * (`isSameOrigin()`), ici `http://localhost:80`.
     *
     * Partagé avec le POST ({@see McpEndpointTest::testThePostAdmitsTheSiteOriginOnly()}).
     *
     * @return iterable<string, array{string, int}>
     */
    public static function originsAgainstTheSite(): iterable
    {
        yield 'la page du site' => ['http://localhost:5173', 200];
        yield 'la même origine que l\'API' => ['http://localhost', 200];
        yield 'autre schéma' => ['https://localhost:5173', 403];
        yield 'autre port' => ['http://localhost:5174', 403];
        yield 'MCP Inspector' => ['http://localhost:6274', 403];
        yield 'origine opaque' => ['null', 403];
        yield 'origine vide' => ['', 403];
        yield 'hôte étranger' => ['https://evil.example', 403];
        yield 'la page du site, barre finale' => ['http://localhost:5173/', 403];
        yield 'la page du site, en capitales' => ['HTTP://LOCALHOST:5173', 200];
        yield 'la même origine que l\'API, port par défaut écrit' => ['http://localhost:80', 200];
    }

    #[DataProvider('originsAgainstTheSite')]
    public function testTheGetAdmitsTheSiteOriginOnly(string $origin, int $expectedStatus): void
    {
        $client = self::createClient();

        $client->request('GET', self::MCP_ENDPOINT, server: ['HTTP_ORIGIN' => $origin] + self::EVENT_STREAM);

        self::assertResponseStatusCodeSame($expectedStatus);
        if (403 === $expectedStatus) {
            self::assertSame(self::INVALID_ORIGIN, $client->getResponse()->getContent());
        }
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

    /**
     * Le store de sessions injoignable (#731, R7). Sous un cache en panne, le
     * `Psr16SessionStore` du SDK lève {@see SessionStoreException} au contrôle
     * d'existence, que le décorateur borné laisse passer : elle sortait du
     * contrôleur, et le noyau répondait 500. Le GET suit le SDK (décision du
     * 2026-11-06), qui en fait au POST un 503 : la session existe peut-être,
     * le client réessaie au lieu de se réinitialiser (un 404).
     *
     * **Le 503 ne vaut que pour un identifiant à contrôler** : un GET sans
     * en-tête de session — ou dont l'en-tête est vide ou « 0 », que le
     * transport lit absent — ne consulte pas le store, et reste servi quand
     * celui-ci est en panne.
     *
     * @return iterable<string, array{array<string, string>, int}>
     */
    public static function sessionHeadersOnAnUnreachableStore(): iterable
    {
        yield 'un identifiant à contrôler' => [['HTTP_MCP_SESSION_ID' => Uuid::v4()->toRfc4122()], 503];
        yield 'sans en-tête de session' => [[], 200];
        yield 'en-tête vide, lu absent' => [['HTTP_MCP_SESSION_ID' => ''], 200];
        yield 'en-tête « 0 », lu absent' => [['HTTP_MCP_SESSION_ID' => '0'], 200];
    }

    /**
     * @param array<string, string> $server
     */
    #[DataProvider('sessionHeadersOnAnUnreachableStore')]
    public function testAGetOnAnUnreachableSessionStore(array $server, int $expectedStatus): void
    {
        self::bootKernel();
        $mcpLog = new TestHandler();
        $logger = new Logger('mcp', [$mcpLog]);
        $controller = $this->controllerWith(
            ['localhost'],
            new BoundedSessionStore(new Psr16SessionStore(new UnreachableSimpleCache()), new TokenStorage(), $logger),
            $logger,
        );

        $response = $controller->handle(Request::create('/api/mcp', server: $server + self::EVENT_STREAM));

        self::assertSame($expectedStatus, $response->getStatusCode());
        if (200 === $expectedStatus) {
            // Servi sans avoir consulté le store : ni panne ni session illisible à dire.
            self::assertSame("retry: 300000\n\n", $response->getContent());
            self::assertSame([], $mcpLog->getRecords());

            return;
        }

        // Le 503 du transport : l'erreur JSON-RPC interne, sans `id` (un GET
        // ne porte aucune requête à laquelle la rattacher).
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertSame(
            ['jsonrpc' => '2.0', 'error' => ['code' => self::INTERNAL_ERROR, 'message' => self::STORE_UNAVAILABLE]],
            json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR),
        );

        // Une seule ligne, `error`, et rien d'autre que l'exception : jamais
        // l'identifiant de session, qui vaut accès à la session.
        $records = $mcpLog->getRecords();
        self::assertCount(1, $records);
        $record = $records[0];
        self::assertSame(Level::Error, $record->level);
        self::assertSame(self::STORE_UNAVAILABLE, $record->message);
        self::assertSame(['exception'], array_keys($record->context));
        self::assertInstanceOf(SessionStoreException::class, $record->context['exception']);

        // Telle que le canal `mcp` l'écrit, passée par son rédacteur : toujours
        // `error` (une panne du serveur, que le handler de production prend),
        // et l'identifiant nulle part.
        $written = new SdkChannelRedactor()($record);
        self::assertSame(Level::Error, $written->level);
        self::assertArrayNotHasKey('session_id', $written->context);
        self::assertStringNotContainsString($server['HTTP_MCP_SESSION_ID'], $this->asWritten($written));
    }

    /**
     * Le 503 du GET est celui du POST, sur la vraie chaîne : le store en
     * panne posé dans le conteneur, le même identifiant présenté au SDK
     * (POST) puis au contrôleur (GET). Même statut, mêmes en-têtes, même
     * erreur JSON-RPC — le POST y ajoute l'`id` de sa requête, le GET n'en a
     * pas. Et la même ligne `error` sur le canal `mcp`, lue sur la sonde
     * après le rédacteur du canal : celle du SDK porte une clé `session_id`,
     * que le rédacteur retient ; celle du GET ne la porte pas, et aucune des
     * deux ne cite l'identifiant.
     */
    public function testTheGetAnswersAnUnreachableStoreAsThePostDoes(): void
    {
        $client = self::createClient();
        // Un seul noyau pour les deux requêtes : le store posé y reste.
        $client->disableReboot();
        self::getContainer()->set('mcp.server.portfolio.session.store', new BoundedSessionStore(
            new Psr16SessionStore(new UnreachableSimpleCache()),
            new TokenStorage(),
            new NullLogger(),
        ));
        $sessionId = Uuid::v4()->toRfc4122();

        $this->postMcp($client, $sessionId, ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/list']);
        $post = $client->getResponse();
        $postErrors = $this->storeUnavailableErrors();

        $client->request('GET', self::MCP_ENDPOINT, server: ['HTTP_MCP_SESSION_ID' => $sessionId] + self::EVENT_STREAM);
        $get = $client->getResponse();
        $getErrors = $this->storeUnavailableErrors();

        self::assertSame(503, $post->getStatusCode(), 'Le SDK ne répond plus 503 au POST : la référence du GET a changé.');
        self::assertSame($post->getStatusCode(), $get->getStatusCode());
        self::assertSame($this->headersOf($post), $this->headersOf($get));

        $error = ['code' => self::INTERNAL_ERROR, 'message' => self::STORE_UNAVAILABLE];
        self::assertSame(['jsonrpc' => '2.0', 'id' => 7, 'error' => $error], json_decode((string) $post->getContent(), true, flags: \JSON_THROW_ON_ERROR));
        self::assertSame(['jsonrpc' => '2.0', 'error' => $error], json_decode((string) $get->getContent(), true, flags: \JSON_THROW_ON_ERROR));

        self::assertCount(1, $postErrors);
        self::assertSame(SdkChannelRedactor::WITHHELD, $postErrors[0]->context['session_id'] ?? null);
        self::assertStringNotContainsString($sessionId, $this->asWritten($postErrors[0]));

        self::assertCount(1, $getErrors);
        self::assertArrayNotHasKey('session_id', $getErrors[0]->context);
        self::assertStringNotContainsString($sessionId, $this->asWritten($getErrors[0]));
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
     * Les lignes `error` « Session store is unavailable. » que la dernière
     * requête a laissées sur le canal `mcp`, telles que la sonde les reçoit —
     * après le rédacteur du canal.
     *
     * @return list<LogRecord>
     */
    private function storeUnavailableErrors(): array
    {
        return array_values(array_filter(
            self::allChannelsLogRecordsOf('mcp'),
            static fn (LogRecord $record): bool => Level::Error === $record->level && self::STORE_UNAVAILABLE === $record->message,
        ));
    }

    /** Un enregistrement tel qu'un handler JSON l'écrirait : son message et tout son contexte. */
    private function asWritten(LogRecord $record): string
    {
        return $record->message.json_encode($record->context, \JSON_THROW_ON_ERROR);
    }

    /**
     * Les en-têtes d'une réponse, sans sa date.
     *
     * @return array<string, list<string|null>>
     */
    private function headersOf(Response $response): array
    {
        $headers = $response->headers->all();
        unset($headers['date']);
        ksort($headers);

        return $headers;
    }

    /**
     * Le contrôleur, construit avec les services du conteneur, une autre
     * liste d'hôtes — en test, `allowed_hosts` n'en porte qu'un (`localhost`)
     * —, un store de sessions et un journal au choix du test. Le contrôleur
     * du bundle qu'il décore ne sert pas au GET.
     *
     * @param list<string> $allowedHosts
     */
    private function controllerWith(array $allowedHosts, SessionStoreInterface $sessionStore, LoggerInterface $logger): McpEndpointController
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
            $sessionStore,
            new ServerAnswers(),
            $logger,
        );
    }
}
