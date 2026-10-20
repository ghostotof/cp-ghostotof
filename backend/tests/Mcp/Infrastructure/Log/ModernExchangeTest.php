<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Infrastructure\Log;

use App\Mcp\Infrastructure\Log\McpCallOutcome;
use App\Mcp\Infrastructure\Log\ModernExchange;
use App\Tests\Support\HttpJson;
use Mcp\Server\Stateless\RequestMeta;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ce que `McpCallLogTest` ne peut pas exercer sur le vrai serveur (#664) : un
 * flux SSE de l'ère moderne — `subscriptions/listen` tient le sien 30 s —, et
 * les requêtes que l'ère moderne ne sert pas.
 */
final class ModernExchangeTest extends TestCase
{
    use HttpJson;

    private const string MODERN_REVISION = '2026-07-28';

    /**
     * `listen` n'est pas un message du `MessageFactory` : il est nommé par la
     * constante du protocole sans état, et son flux, illisible ici, est jugé
     * sur son statut.
     */
    public function testAListenStreamIsNamedAndJudgedOnItsStatus(): void
    {
        $exchange = $this->read($this->modernPost('subscriptions/listen'), new StreamedResponse(null, 200, ['Content-Type' => 'text/event-stream']));

        self::assertNotNull($exchange);
        self::assertSame('subscriptions/listen', $exchange->method);
        self::assertNull($exchange->request);
        self::assertSame(McpCallOutcome::Success, $exchange->outcome);
        self::assertNull($exchange->errorCode);
    }

    /** Une notification refusée : 400 sans corps, une erreur de protocole sans code. */
    public function testAnEmptyRefusalIsAProtocolErrorWithoutCode(): void
    {
        $exchange = $this->read($this->modernPost('notifications/initialized', id: null), new Response('', 400));

        self::assertNotNull($exchange);
        self::assertSame('notifications/initialized', $exchange->method);
        self::assertSame(McpCallOutcome::ProtocolError, $exchange->outcome);
        self::assertNull($exchange->errorCode);
    }

    /**
     * Le SDK acquitte une notification sans la construire : un `_meta` scalaire,
     * que sa fabrique ne sait pas typer (une `TypeError`), ne doit pas faire
     * perdre la ligne (audit de #664).
     */
    public function testANotificationItsFactoryCannotBuildHasNoMethod(): void
    {
        $request = $this->post(
            self::jsonBody(['jsonrpc' => '2.0', 'method' => 'notifications/tools/list_changed', 'params' => ['_meta' => 1]]),
            ['HTTP_MCP_PROTOCOL_VERSION' => self::MODERN_REVISION],
        );

        $exchange = $this->read($request, new Response('', 202));

        self::assertNotNull($exchange);
        self::assertNull($exchange->method);
        self::assertSame(McpCallOutcome::Success, $exchange->outcome);
    }

    /**
     * Le SDK lit les en-têtes après le pont PSR-7, qui rogne espaces et
     * tabulations : la révision suivie d'une tabulation reste moderne.
     */
    public function testAHeaderIsTrimmedAsTheSdkReadsIt(): void
    {
        $request = $this->modernPost('notifications/initialized', id: null, version: self::MODERN_REVISION."\t");

        self::assertNotNull($this->read($request, new Response('', 202)));
    }

    /**
     * Le pont PSR-7 écarte un en-tête qui porte un caractère de contrôle : le
     * SDK ne voit alors que l'enveloppe du corps, et sert la requête par l'ère
     * moderne.
     */
    public function testAHeaderWithAControlCharacterIsDroppedAsTheSdkDropsIt(): void
    {
        $request = $this->modernPost('tools/list', version: self::MODERN_REVISION."\x01");

        self::assertNotNull($this->read($request, new Response('{}', 200, ['Content-Type' => 'application/json'])));
    }

    /**
     * Un refus de bord du SDK (Origin, Host), rendu avant que son classifieur
     * tourne : une réponse qui n'est pas du protocole, la ligne de repli la dit.
     */
    public function testARefusalAtTheEdgeIsNotAModernExchange(): void
    {
        $response = new Response('Forbidden: Invalid Origin header.', 403, ['Content-Type' => 'text/plain']);

        self::assertNull($this->read($this->modernPost('tools/list'), $response));
    }

    /** Un acquittement sans corps, quel que soit le type que Symfony lui prête. */
    public function testAnEmptyAcknowledgementIsAModernExchange(): void
    {
        $response = new Response('', 202, ['Content-Type' => 'text/html; charset=UTF-8']);

        self::assertNotNull($this->read($this->modernPost('notifications/initialized', id: null), $response));
    }

    /** Une erreur JSON-RPC dont le code n'est pas un entier : l'issue sans code. */
    public function testAnErrorWithoutAnIntegerCodeHasNoCode(): void
    {
        $response = new Response(self::jsonBody(['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => 'x', 'message' => 'm']]), 200, ['Content-Type' => 'application/json']);

        $exchange = $this->read($this->modernPost('tools/list'), $response);

        self::assertNotNull($exchange);
        self::assertSame(McpCallOutcome::ProtocolError, $exchange->outcome);
        self::assertNull($exchange->errorCode);
    }

    public function testAHandshakeRequestIsNotModern(): void
    {
        $request = Request::create('/api/mcp', 'POST', server: ['HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18'], content: self::jsonBody(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']));

        self::assertNull($this->read($request, new Response('{}')));
    }

    public function testAGetIsNotModern(): void
    {
        self::assertNull($this->read(Request::create('/api/mcp', 'GET', server: ['HTTP_MCP_PROTOCOL_VERSION' => self::MODERN_REVISION]), new Response()));
    }

    private function modernPost(string $method, ?int $id = 1, string $version = self::MODERN_REVISION): Request
    {
        $message = ['jsonrpc' => '2.0', 'method' => $method, 'params' => ['_meta' => [
            RequestMeta::PROTOCOL_VERSION => self::MODERN_REVISION,
            RequestMeta::CLIENT_CAPABILITIES => new stdClass(),
        ]]];
        if (null !== $id) {
            $message['id'] = $id;
        }

        return $this->post(self::jsonBody($message), [
            'HTTP_MCP_PROTOCOL_VERSION' => $version,
            'HTTP_MCP_METHOD' => $method,
        ]);
    }

    /** Avec le pont PSR-7 que le bundle donne à son contrôleur. */
    private function read(Request $request, Response $response): ?ModernExchange
    {
        return ModernExchange::tryRead($request, $response, new PsrHttpFactory());
    }

    /**
     * @param array<string, string> $server
     */
    private function post(string $body, array $server): Request
    {
        return Request::create('/api/mcp', 'POST', server: $server, content: $body);
    }
}
