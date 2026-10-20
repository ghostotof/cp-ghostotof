<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Infrastructure\Http;

use App\Mcp\Infrastructure\Http\InboundMessageRefusal;
use App\Mcp\Infrastructure\Session\BoundedSessionStore;
use App\Tests\Mcp\Support\SpeaksMcp;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Server\Protocol;
use Mcp\Server\Session\Session;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClassConstant;
use stdClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Ce que le serveur MCP refuse avant le SDK (#644), parce que le SDK le
 * servirait ou le recopierait en session sans borne :
 *
 * - un lot JSON-RPC de plusieurs messages : le SDK 0.8.1 en accepte jusqu'à
 *   100 par requête HTTP, codé en dur — 100 `tools/call` pour une seule
 *   requête comptée par la zone nginx `mcp` ;
 * - un `initialize` dont les déclarations (`clientInfo`, `capabilities`)
 *   dépassent la borne : le SDK les recopie telles quelles en session ;
 * - une réponse du client : le SDK la range en session sous un identifiant
 *   que le client choisit, et ce serveur n'envoie aucune requête au client ;
 * - `subscriptions/listen` (#675) : le SDK tient le flux 30 s, un worker
 *   php-fpm pris, pour un serveur qui n'émet aucune notification.
 */
final class InboundMessageRefusalTest extends WebTestCase
{
    use SpeaksMcp;

    private const array CLIENT_INFO = ['name' => 'phpunit', 'version' => '1.0.0'];

    private const string TWO_PINGS = '[{"jsonrpc":"2.0","id":2,"method":"ping"},{"jsonrpc":"2.0","id":3,"method":"ping"}]';

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        self::forgetMcpSessions($this->connection());
        parent::tearDown();
    }

    public function testABatchOfSeveralMessagesIsRefusedWithAJsonRpcInvalidRequest(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $this->postMcp($client, $sessionId, [
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
            ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'prompts/list'],
        ]);

        $this->assertRefused($client, InboundMessageRefusal::SeveralMessages);
    }

    /** Le refus précède la session : un lot sans `initialize` est refusé pareil. */
    public function testABatchIsRefusedWithoutASession(): void
    {
        $client = self::createClient();

        $this->postRawMcp($client, null, self::TWO_PINGS);

        $this->assertRefused($client, InboundMessageRefusal::SeveralMessages);
    }

    /** Des blancs avant le crochet ne font pas passer le lot. */
    public function testLeadingWhitespaceDoesNotHideABatch(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $this->postRawMcp($client, $sessionId, " \r\n\t".self::TWO_PINGS);

        $this->assertRefused($client, InboundMessageRefusal::SeveralMessages);
    }

    /** Le routeur décode `%6D` : le refus aussi (CanonicalPath, issue #77). */
    public function testAPercentEncodedPathIsRefusedToo(): void
    {
        $client = self::createClient();

        $client->request('POST', '/api/%6Dcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
        ], content: self::TWO_PINGS);

        $this->assertRefused($client, InboundMessageRefusal::SeveralMessages);
    }

    /**
     * Le SDK décode en tableau associatif (`json_decode(..., true)`) puis
     * tient pour un lot toute liste PHP : un objet aux clés `"0"`, `"1"`…
     * en est une, et passait le refus quand celui-ci ne regardait que le
     * crochet (relectures de #644, mesuré : 100 `tools/call` servis).
     */
    public function testAnObjectWithListKeysIsABatchToo(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $this->postRawMcp($client, $sessionId, '{"0":{"jsonrpc":"2.0","id":2,"method":"ping"},"1":{"jsonrpc":"2.0","id":3,"method":"ping"}}');

        $this->assertRefused($client, InboundMessageRefusal::SeveralMessages);
    }

    /** Un objet à une seule clé `"0"` est un lot d'un message : le SDK le sert. */
    public function testAnObjectWithASingleListKeyIsServed(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $this->postRawMcp($client, $sessionId, '{"0":{"jsonrpc":"2.0","id":2,"method":"ping"}}');

        self::assertResponseIsSuccessful();
        $this->assertNotRefused($client);
    }

    /** Un lot vide n'est pas un lot de plusieurs messages : le SDK le refuse lui-même. */
    public function testAnEmptyBatchIsLeftToTheSdk(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $this->postRawMcp($client, $sessionId, '[]');

        $this->assertNotRefused($client);
    }

    /** Un lot d'un seul message coûte un message : le SDK le sert. */
    public function testABatchOfOneMessageIsServed(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $this->postMcp($client, $sessionId, [['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping']]);

        self::assertResponseIsSuccessful();
        $this->assertNotRefused($client);
    }

    public function testASingleMessageIsServed(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $this->postMcp($client, $sessionId, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping']);

        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->decodeMcpResponse($client)['id']);
    }

    /** Un JSON illisible qui commence par un crochet reste l'affaire du SDK (erreur d'analyse). */
    public function testUnreadableJsonIsLeftToTheSdk(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $this->postRawMcp($client, $sessionId, '[{"jsonrpc":');

        $this->assertNotRefused($client);
        self::assertSame(-32700, $this->decodeMcpResponse($client)['error']['code']);
    }

    /** Seul le POST porte des messages : un GET n'est jamais lu comme un lot. */
    public function testAGetIsNeverReadAsABatch(): void
    {
        $client = self::createClient();

        $client->request('GET', self::MCP_ENDPOINT, server: ['HTTP_ACCEPT' => 'text/event-stream'], content: self::TWO_PINGS);

        self::assertResponseIsSuccessful();
        $this->assertNotRefused($client);
    }

    /**
     * Un `initialize` qui déclare plus que la borne est refusé, et le SDK ne
     * l'a jamais vu : aucune session n'est écrite.
     */
    public function testAnInitializeDeclaringMoreThanTheBoundIsRefusedAndOpensNoSession(): void
    {
        $client = self::createClient();
        $before = $this->sessionRows();

        $this->postInitializeDeclaring($client, $this->capabilitiesWeighing(InboundMessageRefusal::MAX_DECLARATION_BYTES + 1));

        $this->assertRefused($client, InboundMessageRefusal::OversizedDeclaration);
        self::assertFalse($client->getResponse()->headers->has(self::MCP_SESSION_HEADER));
        self::assertSame($before, $this->sessionRows());
    }

    /** Pile à la borne : l'`initialize` aboutit. */
    public function testAnInitializeDeclaringExactlyTheBoundIsServed(): void
    {
        $client = self::createClient();

        $this->postInitializeDeclaring($client, $this->capabilitiesWeighing(InboundMessageRefusal::MAX_DECLARATION_BYTES));

        self::assertResponseIsSuccessful();
        self::assertTrue($client->getResponse()->headers->has(self::MCP_SESSION_HEADER));
    }

    /**
     * La borne se mesure comme la session stocke : `json_encode` sans
     * `JSON_UNESCAPED_UNICODE`, où chaque `é` prend six octets. Un texte
     * accentué de moitié moins de caractères que la borne la dépasse.
     */
    public function testTheBoundCountsTheBytesTheSessionStores(): void
    {
        $client = self::createClient();

        $this->postInitializeDeclaring($client, ['experimental' => ['x' => str_repeat('é', intdiv(InboundMessageRefusal::MAX_DECLARATION_BYTES, 4))]]);

        $this->assertRefused($client, InboundMessageRefusal::OversizedDeclaration);
    }

    /** Une réponse du client : aucune requête du serveur ne l'attend, elle est refusée. */
    public function testAClientResultIsRefused(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $this->postMcp($client, $sessionId, ['jsonrpc' => '2.0', 'id' => 99, 'result' => new stdClass()]);

        $this->assertRefused($client, InboundMessageRefusal::ClientResponse);
    }

    /** Une erreur rendue par le client est une réponse aussi, identifiant nul compris. */
    public function testAClientErrorIsRefusedEvenWithoutAnId(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $this->postMcp($client, $sessionId, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32000, 'message' => 'no']]);

        $this->assertRefused($client, InboundMessageRefusal::ClientResponse);
    }

    /** Dans un lot d'un message aussi : le lot ne sert pas de détour. */
    public function testAClientResponseInABatchOfOneIsRefused(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $this->postMcp($client, $sessionId, [['jsonrpc' => '2.0', 'id' => 99, 'result' => new stdClass()]]);

        $this->assertRefused($client, InboundMessageRefusal::ClientResponse);
    }

    /**
     * Le SDK lit `error`, puis `result`, avant `method`
     * ({@see \Mcp\JsonRpc\MessageFactory}) : une requête qui porte un résultat
     * ou une erreur est pour lui une réponse, rangée en session sous
     * l'identifiant choisi (#671). Elle passait le refus, qui exigeait
     * l'absence de `method`, et la session grossissait à chaque envoi.
     */
    #[DataProvider('requestsCarryingAResponse')]
    public function testARequestCarryingAResponseIsRefusedAndStoresNoResponse(string $message): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $this->postRawMcp($client, $sessionId, $message);

        $this->assertRefused($client, InboundMessageRefusal::ClientResponse);
        self::assertSame([], $this->storedClientResponses($sessionId));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function requestsCarryingAResponse(): iterable
    {
        yield 'résultat' => ['{"jsonrpc":"2.0","id":"a1","method":"ping","result":{}}'];
        yield 'erreur' => ['{"jsonrpc":"2.0","id":"a1","method":"ping","error":{"code":-32000,"message":"no"}}'];
    }

    /**
     * Témoin de la lecture ci-dessus : une réponse rangée comme
     * {@see Protocol::handleResponse()} la range — sous sa clé, par la
     * {@see Session} du SDK — est bien relue. Sans lui, une clé renommée par le
     * SDK laisserait le test précédent vert sans rien prouver.
     */
    public function testTheSessionReadingSeesAResponseStoredAsTheSdkStoresIt(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $session = new Session($this->sessionStore(), Uuid::fromString($sessionId));
        $session->set($this->sdkResponsesKey().'.a1', ['jsonrpc' => '2.0', 'id' => 'a1', 'result' => []]);
        self::assertTrue($session->save());

        self::assertSame(['a1' => ['jsonrpc' => '2.0', 'id' => 'a1', 'result' => []]], $this->storedClientResponses($sessionId));
    }

    /** Une notification n'est pas une réponse : elle passe. */
    public function testANotificationIsServed(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $this->postMcp($client, $sessionId, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

        self::assertResponseStatusCodeSame(202);
    }

    /**
     * Un `_meta` qui n'est pas un objet : la fabrique du SDK le range dans une
     * propriété typée tableau d'une notification, et lève une `TypeError`
     * (audit de #664) — une ligne `error` à volonté sur le canal `mcp`.
     */
    #[DataProvider('scalarMetas')]
    public function testAScalarMetaIsRefused(int|string|bool $meta): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $this->postMcp($client, $sessionId, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized', 'params' => ['_meta' => $meta]]);

        $this->assertRefused($client, InboundMessageRefusal::MalformedMeta);
    }

    /**
     * @return iterable<string, array{int|string|bool}>
     */
    public static function scalarMetas(): iterable
    {
        yield 'un entier' => [1];
        yield 'du texte' => ['x'];
        yield 'un booléen' => [true];
    }

    public function testAScalarMetaIsRefusedInTheSessionlessRevisionToo(): void
    {
        $client = self::createClient();

        $this->postMcp($client, null, ['jsonrpc' => '2.0', 'method' => 'notifications/tools/list_changed', 'params' => ['_meta' => 1]], [
            'HTTP_MCP_PROTOCOL_VERSION' => self::MCP_MODERN_REVISION,
            'HTTP_MCP_METHOD' => 'notifications/tools/list_changed',
        ]);

        $this->assertRefused($client, InboundMessageRefusal::MalformedMeta);
    }

    /**
     * Un `clientInfo` de la révision sans session que le SDK ne sait pas lire :
     * il le construit hors de tout `try` et répondait 500, une ligne `critical`
     * à volonté (audit de #664).
     *
     * @param array<string, mixed> $clientInfo
     */
    #[DataProvider('malformedClientInfos')]
    public function testAMalformedSessionlessClientInfoIsRefused(array $clientInfo): void
    {
        $client = self::createClient();

        $this->postModernMcp($client, 'tools/list', clientInfo: $clientInfo);

        $this->assertRefused($client, InboundMessageRefusal::MalformedClientInfo);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformedClientInfos(): iterable
    {
        yield 'un nom qui n\'est pas du texte' => [['name' => 1, 'version' => '1.0.0']];
        yield 'sans version' => [['name' => 'phpunit']];
        yield 'un nom vide' => [['name' => '', 'version' => '1.0.0']];
        yield 'une icône qui n\'est pas un objet' => [['name' => 'phpunit', 'version' => '1.0.0', 'icons' => ['x']]];
    }

    public function testAWellFormedSessionlessClientInfoIsServed(): void
    {
        $client = self::createClient();

        $this->postModernMcp($client, 'tools/list', clientInfo: self::CLIENT_INFO);

        self::assertResponseStatusCodeSame(200);
        $this->assertNotRefused($client);
    }

    /**
     * `subscriptions/listen` (#675) : le SDK tient le flux 30 s, un worker
     * php-fpm pris pour toute la durée, alors que ce serveur n'émet aucune
     * notification. Refusé avant le SDK, sans flux ouvert.
     */
    public function testASessionlessListenIsRefused(): void
    {
        $client = self::createClient();

        $this->postModernMcp($client, 'subscriptions/listen', ['notifications' => ['toolsListChanged' => true]], id: 'abo-1');

        $this->assertListenRefused($client, 'abo-1');
    }

    /**
     * Le classifieur du SDK choisit l'ère d'après le corps : un `listen` de la
     * révision sans session passe aussi sous un en-tête de session quelconque.
     * Le refus lit la méthode, pas l'en-tête.
     */
    public function testASessionlessListenUnderASessionHeaderIsRefusedToo(): void
    {
        $client = self::createClient();

        $this->postModernMcp($client, 'subscriptions/listen', server: ['HTTP_MCP_SESSION_ID' => Uuid::v4()->toRfc4122()], id: 7);

        $this->assertListenRefused($client, 7);
    }

    /** Dans une session aussi : une seule règle, quelle que soit l'ère. */
    public function testAListenInASessionIsRefused(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $this->postMcp($client, $sessionId, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'subscriptions/listen']);

        $this->assertListenRefused($client, 2);
    }

    /** Un lot d'un message est jugé comme son message. */
    public function testAListenInABatchOfOneIsRefused(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $this->postMcp($client, $sessionId, [['jsonrpc' => '2.0', 'id' => 3, 'method' => 'subscriptions/listen']]);

        $this->assertListenRefused($client, 3);
    }

    /** Sans identifiant (une notification), le refus n'en invente pas. */
    public function testAListenNotificationIsRefusedWithoutAnId(): void
    {
        $client = self::createClient();

        $this->postModernMcp($client, 'subscriptions/listen', id: null);

        $this->assertListenRefused($client, null);
    }

    /**
     * Le refus lit le JSON décodé, comme le SDK : une méthode écrite avec des
     * échappements (`s`, `\/`), et un chemin encodé (`%6D`), sont refusés.
     */
    public function testAnEscapedListenOnAnEncodedPathIsRefused(): void
    {
        $client = self::createClient();

        $client->request('POST', '/api/%6Dcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_MCP_PROTOCOL_VERSION' => self::MCP_MODERN_REVISION,
            'HTTP_MCP_METHOD' => 'subscriptions/listen',
        ], content: '{"jsonrpc":"2.0","id":4,"method":"subscriptions\/listen","params":{"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":{}}}}');

        $this->assertListenRefused($client, 4);
    }

    /**
     * Une requête bien formée dont la méthode n'est pas servie : « Method not
     * found » (`-32601`) avec son identifiant, comme le SDK pour une méthode
     * qu'il ne sert pas — pour lui, le client doit cesser de demander. Un 400,
     * jamais le 404 du SDK sans session : dans une session, un 404 dit au client
     * que sa session a expiré.
     */
    private function assertListenRefused(KernelBrowser $client, int|string|null $id): void
    {
        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        $error = ['code' => -32601, 'message' => InboundMessageRefusal::SubscriptionListen->message()];
        self::assertSame(
            null === $id ? ['jsonrpc' => '2.0', 'error' => $error] : ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error],
            $this->decodeMcpResponse($client),
        );
    }

    /** La forme des refus du transport du SDK : un identifiant illisible est omis, jamais `null`. */
    private function assertRefused(KernelBrowser $client, InboundMessageRefusal $refusal): void
    {
        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame([
            'jsonrpc' => '2.0',
            'error' => [
                'code' => -32600,
                'message' => $refusal->message(),
            ],
        ], $this->decodeMcpResponse($client));
    }

    private function assertNotRefused(KernelBrowser $client): void
    {
        foreach (InboundMessageRefusal::cases() as $refusal) {
            self::assertStringNotContainsString($refusal->message(), (string) $client->getResponse()->getContent());
        }
    }

    /**
     * @param array<string, mixed> $capabilities
     */
    private function postInitializeDeclaring(KernelBrowser $client, array $capabilities): void
    {
        $this->postMcp($client, null, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => self::MCP_PROTOCOL_VERSION,
                'capabilities' => $capabilities,
                'clientInfo' => self::CLIENT_INFO,
            ],
        ]);
    }

    /**
     * Des capacités dont les déclarations encodées pèsent exactement `$bytes`
     * octets, `clientInfo` compris, comme la borne les mesure.
     *
     * @return array<string, mixed>
     */
    private function capabilitiesWeighing(int $bytes): array
    {
        $capabilities = ['experimental' => ['x' => '']];
        $empty = InboundMessageRefusal::declarationBytes(self::CLIENT_INFO, $capabilities);
        $capabilities['experimental']['x'] = str_repeat('a', $bytes - $empty);
        self::assertSame($bytes, InboundMessageRefusal::declarationBytes(self::CLIENT_INFO, $capabilities));

        return $capabilities;
    }

    /**
     * Les réponses du client que la session a enregistrées, lues comme le SDK
     * les lit : par sa {@see Session}, sous la clé de {@see Protocol}.
     *
     * @return array<mixed>
     */
    private function storedClientResponses(string $sessionId): array
    {
        $id = Uuid::fromString($sessionId);
        self::assertTrue($this->sessionStore()->exists($id), 'La session doit exister encore.');
        $responses = new Session($this->sessionStore(), $id)->get($this->sdkResponsesKey(), []);
        self::assertIsArray($responses);

        return $responses;
    }

    /**
     * La clé sous laquelle {@see Protocol::handleResponse()} range les réponses
     * (`_mcp.responses` en 0.8.1), lue dans le SDK : renommée, elle fait
     * échouer la lecture au lieu de la laisser vide.
     */
    private function sdkResponsesKey(): string
    {
        $key = new ReflectionClassConstant(Protocol::class, 'SESSION_RESPONSES')->getValue();
        self::assertIsString($key);

        return $key;
    }

    private function sessionStore(): BoundedSessionStore
    {
        return self::getContainer()->get('mcp.server.portfolio.session.store');
    }

    private function sessionRows(): int
    {
        $count = $this->connection()->fetchOne("SELECT COUNT(*) FROM cache_items WHERE item_id LIKE '%mcp-portfolio-%'");
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }
}
