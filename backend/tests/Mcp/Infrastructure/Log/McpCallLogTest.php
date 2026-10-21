<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Infrastructure\Log;

use App\Tests\Mcp\Support\SpeaksMcp;
use App\Tests\Support\ReadsAllChannelsLog;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Formatter\JsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Le journal d'appels du serveur MCP, canal `mcp_usage` (spec 0007, « Journal
 * des appels », tâche 4, #643) : l'auteur mesure l'usage et repère un abus,
 * sans conserver de donnée personnelle complète.
 *
 * Une ligne `info` par message JSON-RPC traité (requête ou notification) :
 * méthode, outil ou prompt, issue (`success`, `tool-error`, `protocol-error`),
 * code d'erreur JSON-RPC, durée, IP tronquée, et le `clientInfo` que le client a
 * **déclaré** à l'`initialize`. Une requête HTTP sur `/api/mcp` qui n'en traite
 * aucun (JSON illisible, session absente, `GET`) laisse une ligne de repli avec
 * sa méthode et son statut HTTP : rien n'échappe au journal. La révision sans
 * session (2026-07-28), servie sans événement, a la même ligne d'appel (#664).
 * Jamais un argument
 * (le `role` du prompt est du texte libre), jamais un jeton ni l'identifiant de
 * session.
 */
final class McpCallLogTest extends WebTestCase
{
    use ReadsAllChannelsLog;
    use SpeaksMcp;

    private const string CALL = 'MCP call';

    private const string REQUEST_WITHOUT_CALL = 'MCP request without call';

    /** KernelBrowser appelle depuis 127.0.0.1. */
    private const string LOOPBACK_NETWORK = '127.0.0.0';

    private const array DECLARED_CLIENT = ['name' => 'phpunit', 'version' => '1.0.0'];

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        self::forgetMcpSessions(self::getContainer()->get(EntityManagerInterface::class)->getConnection());
        parent::tearDown();
    }

    public function testAToolCallLeavesOneLineWithTheExpectedFields(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        // Une liste vide est un succès : la base de test n'a pas de contributions.
        $this->callMcpTool($client, $sessionId, 'list_contributions', ['locale' => 'en']);

        $line = $this->onlyCallLine();
        self::assertSame(Level::Info, $line->level);
        self::assertSame(self::CALL, $line->message);
        self::assertSame(['method', 'name', 'outcome', 'errorCode', 'durationMs', 'ip', 'declaredClient'], array_keys($line->context));
        self::assertSame('tools/call', $line->context['method']);
        self::assertSame('list_contributions', $line->context['name']);
        self::assertSame('success', $line->context['outcome']);
        self::assertNull($line->context['errorCode']);
        self::assertIsFloat($line->context['durationMs']);
        self::assertGreaterThan(0.0, $line->context['durationMs']);
        self::assertSame(self::LOOPBACK_NETWORK, $line->context['ip']);
        self::assertSame(self::DECLARED_CLIENT, $line->context['declaredClient']);
    }

    public function testTheInitializeItselfCarriesTheClientItDeclares(): void
    {
        $client = self::createClient();
        $this->postMcpInitialize($client, self::DECLARED_CLIENT);
        self::assertResponseIsSuccessful();

        $line = $this->onlyCallLine();
        self::assertSame('initialize', $line->context['method']);
        self::assertNull($line->context['name']);
        self::assertSame('success', $line->context['outcome']);
        self::assertSame(self::DECLARED_CLIENT, $line->context['declaredClient']);
    }

    /** Le client choisit ce qu'il déclare : chaque champ est borné. */
    public function testADeclaredClientIsBounded(): void
    {
        $client = self::createClient();
        // Sous la borne des déclarations (#644, 4 096 octets encodés), bien au-delà des 64 caractères du journal.
        $this->postMcpInitialize($client, ['name' => str_repeat('n', 1_500), 'version' => str_repeat('v', 1_500)]);
        self::assertResponseIsSuccessful();

        $declared = $this->onlyCallLine()->context['declaredClient'];
        self::assertIsArray($declared);
        self::assertSame(str_repeat('n', 64), $declared['name']);
        self::assertSame(str_repeat('v', 64), $declared['version']);
    }

    public function testAToolErrorIsTold(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $result = $this->callMcpTool($client, $sessionId, 'get_about', ['locale' => 'de']);
        self::assertTrue($result['isError'] ?? null);

        $line = $this->onlyCallLine();
        self::assertSame('get_about', $line->context['name']);
        self::assertSame('tool-error', $line->context['outcome']);
        self::assertNull($line->context['errorCode']);
    }

    /**
     * Un nom que le serveur ne connaît pas n'est pas recopié : il est choisi par
     * l'appelant, sans borne.
     */
    public function testAnUnknownToolIsAProtocolErrorWithoutItsName(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $response = $this->mcpCall($client, $sessionId, 'tools/call', ['name' => 'no_such_tool', 'arguments' => new stdClass()]);
        self::assertArrayHasKey('error', $response);

        $line = $this->onlyCallLine();
        self::assertSame('tools/call', $line->context['method']);
        self::assertNull($line->context['name']);
        self::assertSame('protocol-error', $line->context['outcome']);
        self::assertSame(-32602, $line->context['errorCode']);
    }

    public function testAPromptIsNamedButItsArgumentsNever(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $response = $this->mcpCall($client, $sessionId, 'prompts/get', [
            'name' => 'present_for_role',
            'arguments' => ['role' => 'Recrutement chez Sentinelle-643 SARL', 'locale' => 'fr'],
        ]);
        self::assertArrayHasKey('result', $response);

        $line = $this->onlyCallLine();
        self::assertSame('prompts/get', $line->context['method']);
        self::assertSame('present_for_role', $line->context['name']);
        self::assertSame('success', $line->context['outcome']);
        self::assertStringNotContainsStringIgnoringCase('sentinelle', (new JsonFormatter())->format($line));
    }

    /** Ni l'en-tête `Authorization`, ni le cookie `BEARER`, ni l'identifiant de session. */
    public function testNoTokenNorSessionIdReachesTheLine(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);
        $client->getCookieJar()->set(new Cookie('BEARER', 'cookie-sentinelle-643'));

        $this->mcpCall($client, $sessionId, 'tools/list', server: ['HTTP_AUTHORIZATION' => 'Bearer jeton-sentinelle-643']);

        $formatted = (new JsonFormatter())->format($this->onlyCallLine());
        self::assertStringNotContainsString('sentinelle', $formatted);
        self::assertStringNotContainsString($sessionId, $formatted);
    }

    /**
     * Depuis #644, un lot de plusieurs messages est refusé avant le SDK
     * (`InboundMessageRefusalListener`) : seul un lot d'un message atteint le
     * journal, et il y a sa ligne comme un message seul.
     */
    public function testABatchOfOneMessageHasItsLine(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $this->postMcp($client, $sessionId, [['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']]);

        self::assertSame('tools/list', $this->onlyCallLine()->context['method']);
    }

    /**
     * Un message invalide d'un lot (une méthode inconnue) n'émet aucun
     * événement du SDK : il a quand même sa ligne, sans méthode ni nom — ce
     * qu'il a envoyé n'est pas recopié.
     */
    public function testAnInvalidMessageOfABatchHasItsLine(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $this->postMcp($client, $sessionId, [['jsonrpc' => '2.0', 'id' => 3, 'method' => 'no/such-sentinelle']]);

        $line = $this->onlyCallLine();
        self::assertNull($line->context['method']);
        self::assertSame('protocol-error', $line->context['outcome']);
        self::assertSame(-32600, $line->context['errorCode']);
        self::assertSame(self::LOOPBACK_NETWORK, $line->context['ip']);
        self::assertStringNotContainsString('sentinelle', (new JsonFormatter())->format($line));
    }

    /**
     * Un lot refusé (#644) — ici deux requêtes partageant un identifiant, que
     * le client choisit — n'atteint pas le SDK : aucune ligne d'appel, la
     * seule ligne de repli de la requête HTTP.
     */
    public function testARefusedBatchLeavesOnlyTheFallbackLine(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $this->postMcp($client, $sessionId, [
            ['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/list'],
            ['jsonrpc' => '2.0', 'id' => 5, 'method' => 'prompts/list'],
        ]);

        self::assertResponseStatusCodeSame(400);
        $this->assertFallbackLine('POST', 400, 'protocol-error', self::MCP_PROTOCOL_VERSION);
    }

    /**
     * Une réponse du client : refusée avant le SDK depuis #644 (ce serveur
     * n'envoie aucune requête au client). La ligne de repli la dit.
     */
    public function testAPostOfAClientResponseIsARefusal(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $this->postMcp($client, $sessionId, ['jsonrpc' => '2.0', 'id' => 99, 'result' => new stdClass()]);
        self::assertResponseStatusCodeSame(400);

        $this->assertFallbackLine('POST', 400, 'protocol-error', self::MCP_PROTOCOL_VERSION);
    }

    public function testANotificationHasItsLine(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $this->postMcp($client, $sessionId, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

        $line = $this->onlyCallLine();
        self::assertSame('notifications/initialized', $line->context['method']);
        self::assertSame('success', $line->context['outcome']);
    }

    public function testAnUnreadableBodyLeavesTheFallbackLine(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $this->postRawMcp($client, $sessionId, '{"jsonrpc":');

        // Le SDK répond l'erreur -32700 en 200 : le statut seul n'y suffit pas.
        self::assertResponseStatusCodeSame(200);
        $this->assertFallbackLine('POST', 200, 'protocol-error', self::MCP_PROTOCOL_VERSION);
    }

    public function testAnUnknownSessionLeavesTheFallbackLine(): void
    {
        $client = self::createClient();

        $this->postMcp($client, '0199f1e2-0000-7000-8000-000000000000', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);
        self::assertResponseStatusCodeSame(404);

        $this->assertFallbackLine('POST', 404, 'protocol-error', self::MCP_PROTOCOL_VERSION);
    }

    public function testADeleteLeavesTheFallbackLine(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $client->request('DELETE', '/api/mcp', server: ['HTTP_MCP_SESSION_ID' => $sessionId, 'HTTP_MCP_PROTOCOL_VERSION' => self::MCP_PROTOCOL_VERSION]);
        self::assertResponseIsSuccessful();

        $this->assertFallbackLine('DELETE', 200, 'success', self::MCP_PROTOCOL_VERSION);
    }

    /**
     * La révision sans session (2026-07-28, #664) passe par un autre chemin du
     * SDK, qui n'émet aucun événement : sa ligne est lue de la requête et de la
     * réponse HTTP, avec les mêmes champs que l'autre ère. Le `clientInfo` est
     * celui que la requête déclare dans son `_meta`.
     */
    public function testAModernToolCallLeavesOneLineWithTheExpectedFields(): void
    {
        $client = self::createClient();

        $this->postModernMcp($client, 'tools/call', ['name' => 'list_contributions', 'arguments' => ['locale' => 'en']], self::DECLARED_CLIENT);
        self::assertResponseStatusCodeSame(200);

        $line = $this->onlyCallLine();
        self::assertSame(Level::Info, $line->level);
        self::assertSame(['method', 'name', 'outcome', 'errorCode', 'durationMs', 'ip', 'declaredClient'], array_keys($line->context));
        self::assertSame('tools/call', $line->context['method']);
        self::assertSame('list_contributions', $line->context['name']);
        self::assertSame('success', $line->context['outcome']);
        self::assertNull($line->context['errorCode']);
        self::assertIsFloat($line->context['durationMs']);
        self::assertGreaterThan(0.0, $line->context['durationMs']);
        self::assertSame(self::LOOPBACK_NETWORK, $line->context['ip']);
        self::assertSame(self::DECLARED_CLIENT, $line->context['declaredClient']);
    }

    /** L'outil sans route rend une erreur d'outil en phase 1 (ADR 0006 D3). */
    public function testAModernToolErrorIsTold(): void
    {
        $client = self::createClient();

        $this->postModernMcp($client, 'tools/call', ['name' => 'get_nominative_corpus', 'arguments' => new stdClass()]);
        self::assertResponseStatusCodeSame(200);
        self::assertTrue($this->decodeMcpResponse($client)['result']['isError'] ?? null);

        $line = $this->onlyCallLine();
        self::assertSame('get_nominative_corpus', $line->context['name']);
        self::assertSame('tool-error', $line->context['outcome']);
        self::assertNull($line->context['errorCode']);
        self::assertNull($line->context['declaredClient']);
    }

    /** Le nom choisi par l'appelant n'est pas recopié, ni ses arguments. */
    public function testAModernUnknownToolIsAProtocolErrorWithoutItsName(): void
    {
        $client = self::createClient();

        $this->postModernMcp($client, 'tools/call', ['name' => 'no_such_tool_sentinelle', 'arguments' => ['q' => 'argument-sentinelle']]);
        $answer = $this->decodeMcpResponse($client);
        self::assertIsArray($answer['error'] ?? null);

        $line = $this->onlyCallLine();
        self::assertSame('tools/call', $line->context['method']);
        self::assertNull($line->context['name']);
        self::assertSame('protocol-error', $line->context['outcome']);
        self::assertSame($answer['error']['code'], $line->context['errorCode']);
        self::assertStringNotContainsString('sentinelle', (new JsonFormatter())->format($line));
    }

    /** Une méthode que la révision a retirée : connue, refusée (404, `-32601`). */
    public function testAModernRemovedMethodIsAProtocolError(): void
    {
        $client = self::createClient();

        $this->postModernMcp($client, 'ping');
        self::assertResponseStatusCodeSame(404);

        $line = $this->onlyCallLine();
        self::assertSame('ping', $line->context['method']);
        self::assertSame('protocol-error', $line->context['outcome']);
        self::assertSame(-32601, $line->context['errorCode']);
    }

    /** Une méthode que le SDK ne connaît pas : choisie par l'appelant, jamais recopiée. */
    public function testAModernUnknownMethodIsNotCopied(): void
    {
        $client = self::createClient();

        $this->postModernMcp($client, 'no/such-sentinelle');
        self::assertResponseStatusCodeSame(404);

        $line = $this->onlyCallLine();
        self::assertNull($line->context['method']);
        self::assertSame('protocol-error', $line->context['outcome']);
        self::assertSame(-32601, $line->context['errorCode']);
        self::assertStringNotContainsString('sentinelle', (new JsonFormatter())->format($line));
    }

    /**
     * `server/discover` n'est pas un message du `MessageFactory` du SDK : le
     * protocole sans état le sert avant lui. La méthode est nommée quand même.
     */
    public function testAModernDiscoverIsNamed(): void
    {
        $client = self::createClient();

        $this->postModernMcp($client, 'server/discover');
        self::assertResponseStatusCodeSame(200);

        $line = $this->onlyCallLine();
        self::assertSame('server/discover', $line->context['method']);
        self::assertSame('success', $line->context['outcome']);
    }

    /**
     * {@see \App\Mcp\Infrastructure\Log\ModernExchange} construit la fabrique du
     * SDK sans les messages d'extension que `Builder::build()` lui ajoute : une
     * extension activée verrait ses méthodes journalisées sans nom. Le jour où
     * ce test rougit, la lecture doit recevoir la fabrique du serveur.
     */
    public function testTheServerEnablesNoExtension(): void
    {
        $client = self::createClient();

        $this->postModernMcp($client, 'server/discover');

        $capabilities = $this->decodeMcpResponse($client)['result']['capabilities'] ?? null;
        self::assertIsArray($capabilities);
        self::assertArrayNotHasKey('extensions', $capabilities);
    }

    /** Une notification : 202 sans corps, une ligne quand même. */
    public function testAModernNotificationHasItsLine(): void
    {
        $client = self::createClient();

        $this->postModernMcp($client, 'notifications/cancelled', ['requestId' => 4], id: null);
        self::assertResponseStatusCodeSame(202);

        $line = $this->onlyCallLine();
        self::assertSame('notifications/cancelled', $line->context['method']);
        self::assertSame('success', $line->context['outcome']);
        self::assertNull($line->context['errorCode']);
    }

    /** Une révision inconnue dans `_meta` est servie par l'ère moderne, qui la refuse. */
    public function testAModernUnsupportedRevisionIsAProtocolError(): void
    {
        $client = self::createClient();

        $client->request('POST', self::MCP_ENDPOINT, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_MCP_METHOD' => 'tools/list',
        ], content: self::jsonBody([
            'jsonrpc' => '2.0',
            'id' => 8,
            'method' => 'tools/list',
            'params' => ['_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2099-01-01',
                'io.modelcontextprotocol/clientCapabilities' => new stdClass(),
            ]],
        ]));
        self::assertResponseStatusCodeSame(400);

        $line = $this->onlyCallLine();
        self::assertSame('tools/list', $line->context['method']);
        self::assertSame('protocol-error', $line->context['outcome']);
        self::assertIsInt($line->context['errorCode']);
    }

    /**
     * Une réponse du client sous l'enveloppe moderne : refusée avant le SDK
     * (#644), mais le classifieur du SDK la range dans l'ère moderne. Elle a sa
     * ligne d'appel, sans méthode (ce n'est pas une requête), avec le code du
     * refus — l'autre ère n'en a qu'une de repli.
     */
    public function testAModernClientResponseRefusedBeforeTheSdkHasACallLineWithoutMethod(): void
    {
        $client = self::createClient();

        $this->postMcp($client, null, [
            'jsonrpc' => '2.0',
            'id' => 10,
            'method' => 'tools/list',
            'result' => new stdClass(),
            'params' => ['_meta' => [
                'io.modelcontextprotocol/protocolVersion' => self::MCP_MODERN_REVISION,
                'io.modelcontextprotocol/clientCapabilities' => new stdClass(),
            ]],
        ], ['HTTP_MCP_PROTOCOL_VERSION' => self::MCP_MODERN_REVISION, 'HTTP_MCP_METHOD' => 'tools/list']);
        self::assertResponseStatusCodeSame(400);

        $line = $this->onlyCallLine();
        self::assertNull($line->context['method']);
        self::assertSame('protocol-error', $line->context['outcome']);
        self::assertSame(-32600, $line->context['errorCode']);
    }

    /**
     * `subscriptions/listen`, refusé avant le SDK (#675) : la méthode est nommée
     * (le protocole sans état la sert par sa constante, hors de la fabrique),
     * avec le code du refus (`-32601`) — plus un flux de 30 s compté comme un
     * succès.
     */
    public function testAModernListenRefusedBeforeTheSdkIsNamed(): void
    {
        $client = self::createClient();

        $this->postModernMcp($client, 'subscriptions/listen');
        self::assertResponseStatusCodeSame(400);

        $line = $this->onlyCallLine();
        self::assertSame('subscriptions/listen', $line->context['method']);
        self::assertSame('protocol-error', $line->context['outcome']);
        self::assertSame(-32601, $line->context['errorCode']);
    }

    /**
     * Dans une session, le corps ne porte pas l'enveloppe moderne : refusé avant
     * le SDK, le `listen` ne laisse que la ligne de repli, comme les autres refus
     * de cette ère (#675 ; le SDK, qui le refusait lui-même, en écrivait une
     * ligne d'appel sans méthode).
     */
    public function testAListenRefusedInASessionLeavesTheFallbackLine(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $this->postMcp($client, $sessionId, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'subscriptions/listen']);
        self::assertResponseStatusCodeSame(400);

        $this->assertFallbackLine('POST', 400, 'protocol-error', self::MCP_PROTOCOL_VERSION);
    }

    /** Le client choisit ce qu'il déclare à chaque requête : chaque champ est borné. */
    public function testAModernDeclaredClientIsBounded(): void
    {
        $client = self::createClient();

        $this->postModernMcp($client, 'tools/list', clientInfo: ['name' => str_repeat('n', 1_500), 'version' => str_repeat('v', 1_500)]);
        self::assertResponseStatusCodeSame(200);

        $declared = $this->onlyCallLine()->context['declaredClient'];
        self::assertIsArray($declared);
        self::assertSame(str_repeat('n', 64), $declared['name']);
        self::assertSame(str_repeat('v', 64), $declared['version']);
    }

    /**
     * Un en-tête de révision moderne sans enveloppe : le classifieur du SDK le
     * refuse avant toute ère. Aucun message n'est traité, la ligne de repli le
     * dit.
     */
    public function testAModernHeaderWithoutEnvelopeLeavesTheFallbackLine(): void
    {
        $client = self::createClient();

        $this->postMcp($client, null, ['jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/list'], ['HTTP_MCP_PROTOCOL_VERSION' => self::MCP_MODERN_REVISION]);
        self::assertResponseStatusCodeSame(400);

        $this->assertFallbackLine('POST', 400, 'protocol-error', self::MCP_MODERN_REVISION);
    }

    public function testAModernPromptIsNamedButItsArgumentsNever(): void
    {
        $client = self::createClient();

        $this->postModernMcp($client, 'prompts/get', [
            'name' => 'present_for_role',
            'arguments' => ['role' => 'Recrutement chez Sentinelle-664 SARL', 'locale' => 'fr'],
        ]);
        self::assertResponseStatusCodeSame(200);

        $line = $this->onlyCallLine();
        self::assertSame('prompts/get', $line->context['method']);
        self::assertSame('present_for_role', $line->context['name']);
        self::assertSame('success', $line->context['outcome']);
        self::assertStringNotContainsStringIgnoringCase('sentinelle', (new JsonFormatter())->format($line));
    }

    /**
     * Un lot sous l'enveloppe moderne : refusé avant le SDK (#644), et que le
     * classifieur du SDK refuse aussi avant toute ère. La ligne de repli.
     */
    public function testAModernBatchLeavesTheFallbackLine(): void
    {
        $client = self::createClient();
        $meta = ['io.modelcontextprotocol/protocolVersion' => self::MCP_MODERN_REVISION, 'io.modelcontextprotocol/clientCapabilities' => new stdClass()];

        $this->postMcp($client, null, [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => ['_meta' => $meta]],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'prompts/list', 'params' => ['_meta' => $meta]],
        ], ['HTTP_MCP_PROTOCOL_VERSION' => self::MCP_MODERN_REVISION, 'HTTP_MCP_METHOD' => 'tools/list']);
        self::assertResponseStatusCodeSame(400);

        $this->assertFallbackLine('POST', 400, 'protocol-error', self::MCP_MODERN_REVISION);
    }

    /**
     * Un `Origin` étranger : le SDK le refuse à son bord, avant de classer le
     * message. Aucun outil n'a été appelé, la ligne de repli le dit — comme
     * dans l'autre ère.
     */
    public function testAModernRefusalAtTheEdgeLeavesTheFallbackLine(): void
    {
        $client = self::createClient();

        $this->postModernMcp($client, 'tools/call', ['name' => 'get_about', 'arguments' => new stdClass()], server: ['HTTP_ORIGIN' => 'https://evil.example']);
        self::assertResponseStatusCodeSame(403);

        $this->assertFallbackLine('POST', 403, 'protocol-error', self::MCP_MODERN_REVISION);
    }

    /**
     * Un 4xx du framework sous l'enveloppe moderne (audit de #675, C9) : un
     * hôte illisible, venu d'un `X-Forwarded-Host` (KernelBrowser appelle depuis
     * 127.0.0.1, un proxy de confiance), fait lever au routeur une
     * `SuspiciousOperationException`,
     * rendue en 400 `application/json` par l'`ErrorListener`, avant que le
     * contrôleur MCP ou le refus aient vu la requête. Le classifieur du SDK
     * range pourtant le corps dans l'ère moderne : seule compte la réponse de
     * l'un des deux, la ligne de repli dit ce que le serveur n'a pas traité.
     */
    public function testAModernRouterRefusalLeavesTheFallbackLine(): void
    {
        $client = self::createClient();

        $this->postModernMcp($client, 'tools/call', ['name' => 'get_about', 'arguments' => new stdClass()], server: ['HTTP_X_FORWARDED_HOST' => 'local host']);
        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $this->assertFallbackLine('POST', 400, 'protocol-error', self::MCP_MODERN_REVISION);
    }

    /**
     * Le même refus du routeur, l'hôte valide : le pont PSR-7, qui relit l'hôte,
     * ne lève plus rien, et seule la provenance de la réponse sépare un 404 du
     * framework (`application/json` sous `/api`) d'une réponse du protocole. Un
     * écouteur de test, avant le routeur, lève le 404 que celui-ci lèverait.
     */
    public function testAModernNotFoundOfTheFrameworkLeavesTheFallbackLine(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        self::getContainer()->get('event_dispatcher')->addListener(KernelEvents::REQUEST, static function (RequestEvent $event): void {
            // La requête principale seule : l'ErrorListener rend le 404 par une
            // sous-requête, qui repasse ici.
            if ($event->isMainRequest()) {
                throw new NotFoundHttpException('No route found.');
            }
        }, 64);

        $this->postModernMcp($client, 'tools/call', ['name' => 'get_about', 'arguments' => new stdClass()]);
        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $this->assertFallbackLine('POST', 404, 'protocol-error', self::MCP_MODERN_REVISION);
    }

    /**
     * Une notification au `_meta` scalaire, refusée avant le SDK : la fabrique
     * du SDK ne sait pas la construire (une `TypeError`). Elle a quand même sa
     * ligne, sans méthode (audit de #664).
     */
    public function testAModernNotificationWithAScalarMetaHasItsLine(): void
    {
        $client = self::createClient();

        $this->postMcp($client, null, ['jsonrpc' => '2.0', 'method' => 'notifications/tools/list_changed', 'params' => ['_meta' => 1]], [
            'HTTP_MCP_PROTOCOL_VERSION' => self::MCP_MODERN_REVISION,
            'HTTP_MCP_METHOD' => 'notifications/tools/list_changed',
        ]);
        self::assertResponseStatusCodeSame(400);

        $line = $this->onlyCallLine();
        self::assertNull($line->context['method']);
        self::assertSame('protocol-error', $line->context['outcome']);
        self::assertSame(-32600, $line->context['errorCode']);
    }

    /**
     * Un `clientInfo` que le SDK ne sait pas lire (un nom qui n'est pas du
     * texte) : refusé avant le SDK, qui répondait 500. La ligne d'appel a la
     * méthode que la fabrique reconnaît, et le code du refus.
     */
    public function testAModernMalformedClientInfoIsAProtocolError(): void
    {
        $client = self::createClient();

        $this->postModernMcp($client, 'tools/list', clientInfo: ['name' => 1, 'version' => '1.0.0']);
        self::assertResponseStatusCodeSame(400);

        $line = $this->onlyCallLine();
        self::assertSame('tools/list', $line->context['method']);
        self::assertSame('protocol-error', $line->context['outcome']);
        self::assertSame(-32600, $line->context['errorCode']);
    }

    /**
     * Un caractère de contrôle ou de mise en forme (U+202E inverse l'affichage
     * de la suite) rendrait la ligne trompeuse à l'œil : il est retiré.
     */
    public function testADeclaredClientLosesItsControlAndFormatCharacters(): void
    {
        $client = self::createClient();

        $this->postModernMcp($client, 'tools/list', clientInfo: ['name' => "a\u{202E}b\u{200B}c", 'version' => "1\u{0007}.0"]);
        self::assertResponseStatusCodeSame(200);

        self::assertSame(['name' => 'abc', 'version' => '1.0'], $this->onlyCallLine()->context['declaredClient']);
    }

    /** Une révision que le serveur ne connaît pas n'est pas recopiée. */
    public function testAnUnknownRevisionIsNotCopied(): void
    {
        $client = self::createClient();

        $this->postMcp($client, null, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], ['HTTP_MCP_PROTOCOL_VERSION' => 'sentinelle-643']);

        $context = $this->usageRecords()[0]->context;
        self::assertArrayHasKey('protocolVersion', $context);
        self::assertNull($context['protocolVersion']);
    }

    public function testARequestWithoutSessionLeavesTheFallbackLine(): void
    {
        $client = self::createClient();

        $this->postMcp($client, null, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);
        self::assertResponseStatusCodeSame(400);

        $this->assertFallbackLine('POST', 400, 'protocol-error', self::MCP_PROTOCOL_VERSION);
    }

    public function testAGetLeavesTheFallbackLine(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/mcp', server: ['HTTP_ACCEPT' => 'text/event-stream']);
        self::assertResponseIsSuccessful();

        $this->assertFallbackLine('GET', 200, 'success', null);
    }

    public function testAnIpv6CallerIsTruncatedToItsSlash48(): void
    {
        $client = self::createClient(server: ['REMOTE_ADDR' => '2001:db8:85a3:8d3:1319:8a2e:370:7348']);
        $sessionId = $this->initializeMcpSession($client);

        $this->mcpCall($client, $sessionId, 'tools/list');

        self::assertSame('2001:db8:85a3::', $this->onlyCallLine()->context['ip']);
    }

    public function testAnIpv4CallerIsTruncatedToItsSlash24(): void
    {
        $client = self::createClient(server: ['REMOTE_ADDR' => '203.0.113.42']);
        $sessionId = $this->initializeMcpSession($client);

        $this->mcpCall($client, $sessionId, 'tools/list');

        self::assertSame('203.0.113.0', $this->onlyCallLine()->context['ip']);
    }

    /** Une requête hors du serveur MCP n'écrit rien sur ce canal. */
    public function testAnotherRouteWritesNothing(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/about/en');

        self::assertSame([], $this->usageRecords());
    }

    private function assertFallbackLine(string $httpMethod, int $httpStatus, string $outcome, ?string $protocolVersion): void
    {
        $lines = $this->usageRecords();
        self::assertCount(1, $lines);
        $line = $lines[0];
        self::assertSame(Level::Info, $line->level);
        self::assertSame(self::REQUEST_WITHOUT_CALL, $line->message);
        self::assertSame(['httpMethod', 'httpStatus', 'protocolVersion', 'outcome', 'durationMs', 'ip'], array_keys($line->context));
        self::assertSame($httpMethod, $line->context['httpMethod']);
        self::assertSame($httpStatus, $line->context['httpStatus']);
        self::assertSame($protocolVersion, $line->context['protocolVersion']);
        self::assertSame($outcome, $line->context['outcome']);
        self::assertIsFloat($line->context['durationMs']);
        self::assertSame(self::LOOPBACK_NETWORK, $line->context['ip']);
    }

    private function onlyCallLine(): LogRecord
    {
        $lines = $this->callLines();
        self::assertCount(1, $lines, 'Une et une seule ligne d\'appel attendue pour la dernière requête.');

        return $lines[0];
    }

    /**
     * @return list<LogRecord>
     */
    private function callLines(): array
    {
        $lines = $this->usageRecords();
        foreach ($lines as $line) {
            self::assertSame(self::CALL, $line->message);
        }

        return $lines;
    }

    /**
     * La sonde ne garde que la dernière requête du client (le noyau redémarre
     * entre deux) : ce sont les lignes de celle-ci.
     *
     * @return list<LogRecord>
     */
    private function usageRecords(): array
    {
        return self::allChannelsLogRecordsOf('mcp_usage');
    }
}
