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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

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
 * sa méthode et son statut HTTP : rien n'échappe au journal. Jamais un argument
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
        $this->postInitialize($client, ['name' => 'phpunit', 'version' => '1.0.0']);

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
        $this->postInitialize($client, ['name' => str_repeat('n', 10_000), 'version' => str_repeat('v', 10_000)]);

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

    public function testEachMessageOfABatchHasItsLine(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $client->request('POST', '/api/mcp', server: $this->mcpHeaders($sessionId), content: self::jsonBody([
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
            ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'prompts/list'],
        ]));

        self::assertSame(['tools/list', 'prompts/list'], array_map(static fn (LogRecord $line): mixed => $line->context['method'], $this->callLines()));
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

        $client->request('POST', '/api/mcp', server: $this->mcpHeaders($sessionId), content: '{"jsonrpc":');

        $this->assertFallbackLine('POST', $client->getResponse()->getStatusCode(), 'protocol-error');
    }

    public function testARequestWithoutSessionLeavesTheFallbackLine(): void
    {
        $client = self::createClient();

        $this->postMcp($client, null, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);
        self::assertResponseStatusCodeSame(400);

        $this->assertFallbackLine('POST', 400, 'protocol-error');
    }

    public function testAGetLeavesTheFallbackLine(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/mcp', server: ['HTTP_ACCEPT' => 'text/event-stream']);
        self::assertResponseIsSuccessful();

        $this->assertFallbackLine('GET', 200, 'success');
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

    private function assertFallbackLine(string $httpMethod, int $httpStatus, string $outcome): void
    {
        $lines = $this->usageRecords();
        self::assertCount(1, $lines);
        $line = $lines[0];
        self::assertSame(Level::Info, $line->level);
        self::assertSame(self::REQUEST_WITHOUT_CALL, $line->message);
        self::assertSame(['httpMethod', 'httpStatus', 'outcome', 'durationMs', 'ip'], array_keys($line->context));
        self::assertSame($httpMethod, $line->context['httpMethod']);
        self::assertSame($httpStatus, $line->context['httpStatus']);
        self::assertSame($outcome, $line->context['outcome']);
        self::assertIsFloat($line->context['durationMs']);
        self::assertSame(self::LOOPBACK_NETWORK, $line->context['ip']);
    }

    /**
     * @param array<string, string> $clientInfo
     */
    private function postInitialize(KernelBrowser $client, array $clientInfo): void
    {
        $this->postMcp($client, null, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(), 'clientInfo' => $clientInfo],
        ]);
        self::assertResponseIsSuccessful();
    }

    /**
     * @return array<string, string>
     */
    private function mcpHeaders(string $sessionId): array
    {
        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18',
            'HTTP_MCP_SESSION_ID' => $sessionId,
        ];
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
        return array_values(array_filter(self::allChannelsLogRecords(), static fn (LogRecord $record): bool => 'mcp_usage' === $record->channel));
    }
}
