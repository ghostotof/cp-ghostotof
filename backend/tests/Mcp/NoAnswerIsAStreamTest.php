<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Tests\Mcp\Support\SpeaksMcp;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\JsonRpc\MessageFactory;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Server\Stateless\StatelessProtocol;
use ReflectionClass;
use stdClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Aucune réponse du serveur MCP n'est un flux (audit de #675, C5) : sous
 * PHP-FPM, un flux tient un worker tant qu'il dure, et seule la zone nginx
 * `mcpconn` (deux connexions par IP et par pod) le bornerait. #675 refuse
 * `subscriptions/listen`, la seule méthode du SDK 0.8.1 qui en ouvre un
 * d'office ; ce test garde les autres, dans les deux ères.
 *
 * Le SDK passe une réponse en SSE quand le traitement d'une requête se suspend
 * — un outil ou un prompt qui notifie (progression, journal) ou sollicite le
 * client (sampling, elicitation) — et, dans la révision sans session, quand le
 * client accepte `text/event-stream`. Chaque requête part donc comme d'un client
 * qui veut bien d'un flux : `Accept: application/json, text/event-stream` et
 * un `progressToken` dans son `_meta`. Mesuré pendant l'audit : `tools/call`
 * répond alors en `application/json`, en 84 à 190 ms.
 *
 * **Toutes les requêtes que le SDK sait construire** partent, pas seulement
 * celles qu'annonce le serveur : chaque outil et chaque prompt du catalogue tel
 * que `tools/list` et `prompts/list` le rendent, et chaque méthode que le SDK
 * sert sans la publier (`ping`, `resources/*`, `logging/setLevel`…), qui
 * répond une erreur JSON si rien ne la sert. Une méthode qu'une montée du SDK
 * ajoute fait rougir {@see self::testEveryMethodOfTheSdkIsJudged()} tant qu'elle
 * n'a pas sa ligne dans {@see self::params()}.
 *
 * Non couvert, accepté : un outil qui ne notifierait que pour certains
 * arguments (chacun est appelé avec ses valeurs par défaut), un prompt pour
 * certains rôles.
 */
final class NoAnswerIsAStreamTest extends WebTestCase
{
    use SpeaksMcp;

    private const string PROGRESS_TOKEN = 'c5-progress';

    private const string ROLE = 'Senior PHP developer';

    /**
     * Le marqueur de ChatGPT, sur chaque `tools/call` : sans lui, l'appel
     * anonyme de l'outil nominatif reçoit le 401 différé avant le SDK (#648) et
     * l'outil ne tournerait pas — ce test ne garderait rien de sa réponse.
     */
    private const array OPENAI_MARKER = ['openai/subject' => 'c5-subject'];

    /** Les méthodes dont le traitement exécute du code du site, qui peut se suspendre. */
    private const array SUSPENDABLE = ['tools/call', 'prompts/get'];

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
     * Les requêtes que le SDK sait construire, celles qu'il sert avant sa
     * fabrique dans la révision sans session (`*_METHOD` de `StatelessProtocol`),
     * et rien d'autre : la table de {@see self::params()} est fermée.
     */
    public function testEveryMethodOfTheSdkIsJudged(): void
    {
        $sdkMethods = [];
        foreach (new ReflectionClass(MessageFactory::class)->getConstant('REGISTERED_MESSAGES') as $message) {
            if (is_subclass_of($message, Request::class)) {
                $sdkMethods[] = $message::getMethod();
            }
        }

        foreach (new ReflectionClass(StatelessProtocol::class)->getConstants() as $name => $value) {
            if (str_ends_with($name, '_METHOD')) {
                $sdkMethods[] = $value;
            }
        }

        $judged = [...array_keys($this->params()), 'tools/call', 'prompts/get', 'initialize'];
        sort($sdkMethods);
        sort($judged);

        self::assertSame(
            $judged,
            $sdkMethods,
            'Une méthode du SDK que ce test n\'envoie pas : juger si sa réponse peut être un flux, puis lui donner sa ligne dans params().',
        );

        // Une extension ajoute ses méthodes à la fabrique du serveur, pas à la
        // constante lue ci-dessus : le bundle 0.14.1 n'en active aucune.
        $client = self::createClient();
        $this->postModernMcp($client, StatelessProtocol::DISCOVER_METHOD);
        $capabilities = $this->decodeMcpResponse($client)['result']['capabilities'] ?? null;
        self::assertIsArray($capabilities);
        self::assertArrayNotHasKey('extensions', $capabilities, 'Une extension activée : ses méthodes échappent au recensement, les y ajouter.');
    }

    public function testNoAnswerOfTheHandshakeEraIsAStream(): void
    {
        $client = self::createClient();
        // Un `initialize` à lui, avec un `progressToken` comme les autres : la
        // session qu'il ouvre ne sert pas plus loin.
        $this->postMcp($client, null, $this->withProgressToken('initialize', [
            'protocolVersion' => self::MCP_PROTOCOL_VERSION,
            'capabilities' => new stdClass(),
            'clientInfo' => ['name' => 'phpunit', 'version' => '1.0.0'],
        ]));
        $this->assertNotAStream($client, 'initialize');

        $sessionId = $this->initializeMcpSession($client);
        foreach ($this->calls($client, $sessionId) as [$method, $params]) {
            $this->postMcp($client, $sessionId, $this->withProgressToken($method, $params));
            $this->assertNotAStream($client, $method, $params);
        }
    }

    public function testNoAnswerOfTheSessionlessEraIsAStream(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);
        $calls = $this->calls($client, $sessionId);
        $calls[] = ['initialize', []];

        foreach ($calls as [$method, $params]) {
            // Le SDK veut l'URI d'une ressource en `Mcp-Name` ; un nom d'outil
            // ou de prompt, postModernMcp() l'y met lui-même.
            $server = \is_string($params['uri'] ?? null) ? ['HTTP_MCP_NAME' => $params['uri']] : [];
            $this->postModernMcp($client, $method, $params, server: $server, id: 7, meta: $this->metaOf($method));
            $this->assertNotAStream($client, $method, $params);
        }
    }

    /**
     * Les paramètres de chaque méthode sans sujet du catalogue — `tools/call` et
     * `prompts/get` sont tirés de `tools/list` et `prompts/list`. Ceux d'une
     * méthode que le serveur ne sert pas n'ont qu'à être bien formés : elle
     * répond une erreur JSON.
     *
     * @return array<string, array<string, mixed>>
     */
    private function params(): array
    {
        return [
            'ping' => [],
            'tools/list' => [],
            'prompts/list' => [],
            'completion/complete' => [
                'ref' => ['type' => 'ref/prompt', 'name' => 'present_for_role'],
                'argument' => ['name' => 'locale', 'value' => 'f'],
            ],
            'logging/setLevel' => ['level' => 'info'],
            'resources/list' => [],
            'resources/templates/list' => [],
            'resources/read' => ['uri' => 'https://localhost/none'],
            'resources/subscribe' => ['uri' => 'https://localhost/none'],
            'resources/unsubscribe' => ['uri' => 'https://localhost/none'],
            // Des requêtes du serveur vers le client : ce serveur n'en sert aucune.
            'sampling/createMessage' => ['messages' => [], 'maxTokens' => 1],
            'elicitation/create' => ['message' => 'none', 'requestedSchema' => ['type' => 'object', 'properties' => new stdClass()]],
            'roots/list' => [],
            // Inconnue de l'ère à session, servie avant la fabrique dans l'autre.
            StatelessProtocol::DISCOVER_METHOD => [],
            // Le seul flux du SDK 0.8.1, refusé avant lui (#675) : un 400 JSON.
            StatelessProtocol::LISTEN_METHOD => [],
        ];
    }

    /**
     * Chaque méthode de {@see self::params()}, puis chaque outil et chaque prompt
     * que le serveur publie.
     *
     * @return list<array{string, array<string, mixed>}>
     */
    private function calls(KernelBrowser $client, string $sessionId): array
    {
        $calls = [];
        foreach ($this->params() as $method => $params) {
            $calls[] = [$method, $params];
        }

        foreach ($this->listed($client, $sessionId, 'tools/list', 'tools') as $name) {
            $calls[] = ['tools/call', ['name' => $name, 'arguments' => new stdClass()]];
        }

        foreach ($this->listed($client, $sessionId, 'prompts/list', 'prompts') as $name) {
            $calls[] = ['prompts/get', ['name' => $name, 'arguments' => ['role' => self::ROLE, 'locale' => 'fr']]];
        }

        // Aucune ressource aujourd'hui (#654) : la première sera lue ici.
        $resources = $this->mcpCall($client, $sessionId, 'resources/list')['result']['resources'] ?? null;
        self::assertIsArray($resources, 'resources/list a répondu une erreur de protocole.');
        foreach ($resources as $resource) {
            self::assertIsArray($resource);
            self::assertIsString($resource['uri'] ?? null);
            $calls[] = ['resources/read', ['uri' => $resource['uri']]];
        }

        return $calls;
    }

    /**
     * @return list<string>
     */
    private function listed(KernelBrowser $client, string $sessionId, string $method, string $key): array
    {
        $entries = $this->mcpCall($client, $sessionId, $method)['result'][$key] ?? null;
        self::assertIsArray($entries, $method.' a répondu une erreur de protocole.');
        self::assertNotEmpty($entries, $method.' ne publie rien : le test ne garderait rien.');

        $names = [];
        foreach ($entries as $entry) {
            self::assertIsArray($entry);
            self::assertIsString($entry['name'] ?? null);
            $names[] = $entry['name'];
        }

        return $names;
    }

    /**
     * Une requête de l'ère à session, d'un client qui accepte un flux.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function withProgressToken(string $method, array $params): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => $method,
            'params' => ['_meta' => $this->metaOf($method)] + $params,
        ];
    }

    /**
     * Le `_meta` d'un client qui veut bien d'un flux, plus le marqueur
     * `openai/` sur un `tools/call` (OPENAI_MARKER).
     *
     * @return array<string, string>
     */
    private function metaOf(string $method): array
    {
        return ['progressToken' => self::PROGRESS_TOKEN] + ('tools/call' === $method ? self::OPENAI_MARKER : []);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function assertNotAStream(KernelBrowser $client, string $method, array $params = []): void
    {
        $label = \is_string($params['name'] ?? null) ? \sprintf('%s « %s »', $method, $params['name']) : $method;

        self::assertStringStartsWith(
            'application/json',
            (string) $client->getResponse()->headers->get('Content-Type'),
            \sprintf('%s a répondu autrement qu\'en JSON (HTTP %d) : un flux tiendrait un worker php-fpm.', $label, $client->getResponse()->getStatusCode()),
        );

        // Un outil ou un prompt refusé avant de tourner (un en-tête mal formé)
        // répondrait du JSON lui aussi : le garde-fou ne garderait rien.
        if (\in_array($method, self::SUSPENDABLE, true)) {
            self::assertArrayHasKey('result', $this->decodeMcpResponse($client), $label.' n\'a pas été servi.');
        }
    }
}
