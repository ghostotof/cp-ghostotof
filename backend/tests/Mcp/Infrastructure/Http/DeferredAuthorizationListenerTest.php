<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Infrastructure\Http;

use App\Tests\Mcp\Support\HoldsAnMcpConnection;
use App\Tests\Mcp\Support\SpeaksMcp;
use App\Tests\Support\ReadsAllChannelsLog;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Server\Stateless\RequestMeta;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * L'autorisation différée de l'outil nominatif (#648, ADR 0006 D3, spec 0007
 * « Sans connexion ») : sans connexion MCP, un appel de
 * `get_nominative_corpus` reçoit, **avant le SDK**, un 401 portant le défi
 * `WWW-Authenticate` — c'est ce qui fait ouvrir le parcours OAuth aux clients
 * Claude. Sauf quand le message porte une clé `_meta` préfixée `openai/`
 * (ChatGPT) : il passe alors jusqu'à l'outil, qui rend le refus sous la forme
 * que ce client lit (tâche 5). Le marqueur ne choisit que la forme du refus,
 * jamais l'accès.
 *
 * Chaque cas est joué **dans les deux ères** que le SDK sert sur le même
 * point d'accès : dans une session (`2025-06-18`), et sous l'enveloppe sans
 * session de la révision `2026-07-28`, où le nom de l'outil est aussi répété
 * dans l'en-tête `Mcp-Name` — que l'écouteur ne lit jamais : seul le corps,
 * lu par la fabrique de messages du SDK, décide.
 */
final class DeferredAuthorizationListenerTest extends WebTestCase
{
    use HoldsAnMcpConnection;
    use ReadsAllChannelsLog;
    use SpeaksMcp;

    private const string NOMINATIVE_TOOL = 'get_nominative_corpus';

    /** Un outil public qui ne lit pas la base : sa sortie ne dépend pas des données de test. */
    private const string PUBLIC_TOOL = 'get_contact_info';

    private const array CLIENT_INFO = ['name' => 'phpunit', 'version' => '1.0.0'];

    /** Ce qu'un appel de ChatGPT porte dans son `_meta` (préfixe `openai/`). */
    private const array OPENAI_MARKER = ['openai/subject' => 'subject-1'];

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

    /**
     * @return iterable<string, array{string}>
     */
    public static function eras(): iterable
    {
        yield 'dans une session (2025-06-18)' => [self::MCP_PROTOCOL_VERSION];
        yield 'sans session (2026-07-28)' => [self::MCP_MODERN_REVISION];
    }

    #[DataProvider('eras')]
    public function testAnAnonymousCallOfTheNominativeToolIs401(string $era): void
    {
        $this->callTool($era, self::NOMINATIVE_TOOL);

        $this->assertDeferred();
    }

    /**
     * Le marqueur de ChatGPT laisse passer l'appel jusqu'au SDK, qui sert
     * l'outil : la réponse de l'outil à un appel anonyme marqué est la tâche
     * 5 ; ici, seul compte qu'aucun 401 ne l'a précédé.
     */
    #[DataProvider('eras')]
    public function testAMarkedAnonymousCallPassesToTheTool(string $era): void
    {
        $this->callTool($era, self::NOMINATIVE_TOOL, meta: self::OPENAI_MARKER);

        $this->assertServedBySdk();
    }

    /** Un `_meta` sans clé `openai/` n'est pas un marqueur : le défi tient. */
    #[DataProvider('eras')]
    public function testAMetaWithoutTheOpenAiPrefixIsNoMarker(string $era): void
    {
        $this->callTool($era, self::NOMINATIVE_TOOL, meta: ['progressToken' => 'p-1', 'openai' => 'x', 'vendor/openai/subject' => 'x']);

        $this->assertDeferred();
    }

    #[DataProvider('eras')]
    public function testAPublicToolIsNeverDeferred(string $era): void
    {
        $this->callTool($era, self::PUBLIC_TOOL);

        $this->assertServedBySdk();
        self::assertArrayHasKey('structuredContent', $this->toolResult());
    }

    /**
     * Une connexion MCP valide (jeton du vrai parcours OAuth) : le firewall
     * a posé l'identité avant l'écouteur, qui la laisse passer.
     */
    #[DataProvider('eras')]
    public function testAnAuthenticatedCallIsNotDeferred(string $era): void
    {
        $held = $this->holdMcpConnection();

        $this->callTool($era, self::NOMINATIVE_TOOL, bearer: self::bearer($held->accessToken));

        $this->assertServedBySdk();
    }

    /**
     * Un lot d'un seul message passe le refus des lots (#644) et le SDK le
     * sert : son message est jugé comme un message seul.
     */
    #[DataProvider('eras')]
    public function testAOneMessageBatchIsJudgedLikeASingleMessage(string $era): void
    {
        $this->callTool($era, self::NOMINATIVE_TOOL, asBatch: true);

        $this->assertDeferred();
    }

    /**
     * L'en-tête `Mcp-Name` est choisi par l'appelant, et le 401 précède le
     * contrôle du SDK qui le confronte au corps : il ne décide de rien. Un
     * en-tête public sur un corps nominatif reçoit le défi ; un en-tête
     * nominatif sur un corps public n'en reçoit pas — dans une session le SDK
     * sert l'outil, sous l'enveloppe sans session il refuse lui-même
     * l'incohérence (SEP-2243).
     */
    #[DataProvider('eras')]
    public function testTheBodyDecidesNotTheMcpNameHeader(string $era): void
    {
        $this->callTool($era, self::NOMINATIVE_TOOL, server: ['HTTP_MCP_NAME' => self::PUBLIC_TOOL]);
        $this->assertDeferred();

        $this->callTool($era, self::PUBLIC_TOOL, server: ['HTTP_MCP_NAME' => self::NOMINATIVE_TOOL]);
        $this->assertNotDeferred();
        if (self::MCP_PROTOCOL_VERSION === $era) {
            $this->assertServedBySdk();
        } else {
            self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
            self::assertIsArray($this->decodeMcpResponse($this->client)['error'] ?? null);
        }
    }

    /**
     * Un corps que la fabrique du SDK ne lit pas est laissé au SDK, qui le
     * refuse lui-même : une erreur d'analyse dans une session ; sous l'en-tête
     * de la révision sans session, le refus d'une révision annoncée sans son
     * enveloppe, que le SDK oppose avant toute ère.
     */
    #[DataProvider('unreadableBodyRefusals')]
    public function testAnUnreadableBodyIsLeftToTheSdk(string $era, int $sdkErrorCode): void
    {
        $sessionId = self::MCP_PROTOCOL_VERSION === $era ? $this->initializeMcpSession($this->client) : null;

        $this->postRawMcp($this->client, $sessionId, '{', ['HTTP_MCP_PROTOCOL_VERSION' => $era]);

        $this->assertNotDeferred();
        self::assertSame($sdkErrorCode, $this->decodeMcpResponse($this->client)['error']['code'] ?? null);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function unreadableBodyRefusals(): iterable
    {
        yield 'dans une session (2025-06-18) : erreur d\'analyse' => [self::MCP_PROTOCOL_VERSION, Error::PARSE_ERROR];
        yield 'sans session (2026-07-28) : révision sans enveloppe' => [self::MCP_MODERN_REVISION, Error::UNSUPPORTED_PROTOCOL_VERSION];
    }

    /**
     * Le 401 laisse sa trace dans le journal d'appels (`mcp_usage`), comme
     * réponse du serveur : dans une session, la ligne de repli (aucun message
     * traité) avec son statut HTTP ; sous l'enveloppe sans session, la ligne
     * d'appel que `ModernExchange` lit du corps — `tools/call`, l'issue
     * `protocol-error`, sans code ni statut HTTP. **Sans le nom de l'outil** :
     * le journal ne recopie qu'un nom que le registre connaît, et le registre
     * n'est rempli que par la construction du serveur, que ce 401 précède
     * (comme les refus d'avant le SDK). Accepté : un 401 d'un jeton refusé a
     * la même ligne, et seul ce dernier a son « Authenticator failed. » au
     * canal `security` ; le journal d'accès nginx porte le statut.
     */
    #[DataProvider('eras')]
    public function testThe401LeavesACallLine(string $era): void
    {
        $this->callTool($era, self::NOMINATIVE_TOOL);
        $this->assertDeferred();

        $lines = self::allChannelsLogRecordsOf('mcp_usage');
        self::assertCount(1, $lines);
        $line = $lines[0];
        self::assertSame(Level::Info, $line->level);
        self::assertSame('protocol-error', $line->context['outcome'] ?? null);

        if (self::MCP_PROTOCOL_VERSION === $era) {
            $this->assertFallbackLine($line);
        } else {
            self::assertSame('MCP call', $line->message);
            self::assertSame('tools/call', $line->context['method'] ?? null);
            self::assertArrayHasKey('name', $line->context);
            self::assertNull($line->context['name']);
            self::assertNull($line->context['errorCode'] ?? null);
            self::assertArrayNotHasKey('httpStatus', $line->context);
        }
    }

    private function assertFallbackLine(LogRecord $line): void
    {
        self::assertSame('MCP request without call', $line->message);
        self::assertSame('POST', $line->context['httpMethod'] ?? null);
        self::assertSame(Response::HTTP_UNAUTHORIZED, $line->context['httpStatus'] ?? null);
        self::assertSame(self::MCP_PROTOCOL_VERSION, $line->context['protocolVersion'] ?? null);
    }

    /**
     * Un `tools/call` de l'outil nommé, dans l'ère donnée : dans une session
     * ouverte pour lui (avec le même jeton), ou sous l'enveloppe `_meta` de la
     * révision sans session et ses en-têtes SEP-2243 — `$server` les remplace.
     *
     * @param array<string, mixed>  $meta   les clés du `_meta` du message (l'enveloppe en plus, sans session)
     * @param array<string, string> $bearer l'en-tête `Authorization`, à l'`initialize` comme à l'appel
     * @param array<string, string> $server d'autres en-têtes de l'appel, qui priment
     */
    private function callTool(string $era, string $name, array $meta = [], array $bearer = [], array $server = [], bool $asBatch = false): void
    {
        $sessionId = null;
        $headers = [];
        if (self::MCP_MODERN_REVISION === $era) {
            $meta = [
                RequestMeta::PROTOCOL_VERSION => $era,
                RequestMeta::CLIENT_CAPABILITIES => new stdClass(),
                RequestMeta::CLIENT_INFO => self::CLIENT_INFO,
            ] + $meta;
            $headers = ['HTTP_MCP_PROTOCOL_VERSION' => $era, 'HTTP_MCP_METHOD' => 'tools/call', 'HTTP_MCP_NAME' => $name];
        } else {
            $sessionId = $this->initializeMcpSession($this->client, $bearer);
        }

        $params = ['name' => $name, 'arguments' => ['locale' => 'en']];
        if ([] !== $meta) {
            $params['_meta'] = $meta;
        }
        $message = ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => $params];

        $this->postMcp($this->client, $sessionId, $asBatch ? [$message] : $message, $server + $bearer + $headers);
    }

    /** Le défi d'un appel sans connexion : 401 sans corps, jamais mis en cache, sans `error=` (aucun jeton présenté). */
    private function assertDeferred(): void
    {
        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode(), substr((string) $response->getContent(), 0, 300));
        self::assertSame('', (string) $response->getContent());
        $challenge = $response->headers->get('WWW-Authenticate');
        self::assertSame(
            'Bearer resource_metadata="'.$this->publicApiBase().'/.well-known/oauth-protected-resource/api/mcp", scope="nominative:read"',
            $challenge,
        );
        self::assertStringNotContainsString('error=', $challenge);
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    private function assertNotDeferred(): void
    {
        $response = $this->client->getResponse();
        self::assertNotSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertFalse($response->headers->has('WWW-Authenticate'));
    }

    /** Le SDK a répondu au `tools/call` : un `result`, l'erreur d'outil comprise. */
    private function assertServedBySdk(): void
    {
        $this->assertNotDeferred();
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $this->toolResult();
    }

    /**
     * @return array<mixed>
     */
    private function toolResult(): array
    {
        $result = $this->decodeMcpResponse($this->client)['result'] ?? null;
        self::assertIsArray($result, 'tools/call n\'a pas été servi.');

        return $result;
    }
}
