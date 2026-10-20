<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Infrastructure\Log;

use App\Mcp\Infrastructure\Log\McpCallOutcome;
use App\Mcp\Infrastructure\Log\ModernExchange;
use App\Tests\Support\HttpJson;
use Mcp\Server\Stateless\RequestMeta;
use PHPUnit\Framework\TestCase;
use stdClass;
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
        $exchange = ModernExchange::tryRead($this->modernPost('subscriptions/listen'), new StreamedResponse(null, 200, ['Content-Type' => 'text/event-stream']));

        self::assertNotNull($exchange);
        self::assertSame('subscriptions/listen', $exchange->method);
        self::assertNull($exchange->request);
        self::assertSame(McpCallOutcome::Success, $exchange->outcome);
        self::assertNull($exchange->errorCode);
    }

    /** Une notification refusée : 400 sans corps, une erreur de protocole sans code. */
    public function testAnEmptyRefusalIsAProtocolErrorWithoutCode(): void
    {
        $exchange = ModernExchange::tryRead($this->modernPost('notifications/initialized', id: null), new Response('', 400));

        self::assertNotNull($exchange);
        self::assertSame('notifications/initialized', $exchange->method);
        self::assertSame(McpCallOutcome::ProtocolError, $exchange->outcome);
        self::assertNull($exchange->errorCode);
    }

    public function testAHandshakeRequestIsNotModern(): void
    {
        $request = Request::create('/api/mcp', 'POST', server: ['HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18'], content: self::jsonBody(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']));

        self::assertNull(ModernExchange::tryRead($request, new Response('{}')));
    }

    public function testAGetIsNotModern(): void
    {
        self::assertNull(ModernExchange::tryRead(Request::create('/api/mcp', 'GET', server: ['HTTP_MCP_PROTOCOL_VERSION' => self::MODERN_REVISION]), new Response()));
    }

    private function modernPost(string $method, ?int $id = 1): Request
    {
        $message = ['jsonrpc' => '2.0', 'method' => $method, 'params' => ['_meta' => [
            RequestMeta::PROTOCOL_VERSION => self::MODERN_REVISION,
            RequestMeta::CLIENT_CAPABILITIES => new stdClass(),
        ]]];
        if (null !== $id) {
            $message['id'] = $id;
        }

        return Request::create('/api/mcp', 'POST', server: [
            'HTTP_MCP_PROTOCOL_VERSION' => self::MODERN_REVISION,
            'HTTP_MCP_METHOD' => $method,
        ], content: self::jsonBody($message));
    }
}
