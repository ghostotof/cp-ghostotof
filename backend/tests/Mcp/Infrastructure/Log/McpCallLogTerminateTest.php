<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Infrastructure\Log;

use App\Mcp\Infrastructure\Log\McpCallLog;
use Mcp\Capability\Registry;
use Mcp\Event\RequestEvent as McpRequestEvent;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Une requête JSON-RPC restée sans réponse — une exception que le SDK rattrape
 * hors de ses événements, un échange suspendu — est écrite à
 * `kernel.terminate`. La pile des requêtes y est déjà vidée : la ligne garde
 * pourtant l'IP de l'appelant, lue au début de la requête HTTP.
 *
 * Le SDK ne produit pas ce cas sur demande : les événements sont donc émis à la
 * main, sur la vraie classe, dans l'ordre où le noyau et le SDK les émettent.
 */
final class McpCallLogTerminateTest extends TestCase
{
    public function testAnUnansweredRequestIsWrittenAtTerminateWithTheCallerNetwork(): void
    {
        $handler = new TestHandler();
        $log = new McpCallLog(new Logger('mcp_usage', [$handler]), new Registry());
        $kernel = self::createStub(HttpKernelInterface::class);

        $http = Request::create('/api/mcp', 'POST', server: ['REMOTE_ADDR' => '203.0.113.42']);
        $log->onKernelRequest(new RequestEvent($kernel, $http, HttpKernelInterface::MAIN_REQUEST));
        $log->onRequest(new McpRequestEvent(
            CallToolRequest::fromArray(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'get_about', 'arguments' => []]]),
            new Session(new InMemorySessionStore()),
        ));
        $log->onTerminate(new TerminateEvent($kernel, $http, new Response()));

        $records = $handler->getRecords();
        self::assertCount(1, $records);
        self::assertSame(McpCallLog::CALL, $records[0]->message);
        self::assertSame('tools/call', $records[0]->context['method']);
        self::assertSame('protocol-error', $records[0]->context['outcome']);
        self::assertIsFloat($records[0]->context['durationMs']);
        self::assertSame('203.0.113.0', $records[0]->context['ip']);
    }
}
