<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Infrastructure\Log;

use App\Mcp\Infrastructure\Http\ServerAnswers;
use App\Mcp\Infrastructure\Log\McpCallLog;
use LogicException;
use Mcp\Capability\Registry;
use Mcp\Event\RequestEvent as McpRequestEvent;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
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
        $log = new McpCallLog(new Logger('mcp_usage', [$handler]), new Registry(), new PsrHttpFactory(), new ServerAnswers());
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

    /**
     * La lecture de l'ère moderne relit l'échange avec les classes du SDK et du
     * pont PSR-7 : si l'une lève, la requête garde sa ligne de repli, et la
     * suivante ne voit rien de celle-ci (#664).
     */
    public function testAModernReadingThatFailsLeavesTheFallbackLine(): void
    {
        $handler = new TestHandler();
        $failingBridge = self::createStub(HttpMessageFactoryInterface::class);
        $failingBridge->method('createRequest')->willThrowException(new LogicException('pont en panne'));
        $answers = new ServerAnswers();
        $log = new McpCallLog(new Logger('mcp_usage', [$handler]), new Registry(), $failingBridge, $answers);
        $kernel = self::createStub(HttpKernelInterface::class);

        $http = Request::create('/api/mcp', 'POST', server: ['REMOTE_ADDR' => '203.0.113.42'], content: '{}');
        $log->onKernelRequest(new RequestEvent($kernel, $http, HttpKernelInterface::MAIN_REQUEST));
        // Une réponse du serveur, sans quoi la lecture ne serait pas tentée.
        $answer = $answers->record(new Response('{}', 400, ['Content-Type' => 'application/json']));
        $log->onTerminate(new TerminateEvent($kernel, $http, $answer));

        $records = $handler->getRecords();
        self::assertCount(1, $records);
        self::assertSame(McpCallLog::REQUEST_WITHOUT_CALL, $records[0]->message);
        self::assertSame('protocol-error', $records[0]->context['outcome']);
    }
}
