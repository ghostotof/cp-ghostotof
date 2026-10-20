<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Infrastructure\Http;

use App\Tests\Mcp\Support\SpeaksMcp;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Un lot JSON-RPC de plusieurs messages est refusé en 400 avant le SDK (#644).
 *
 * Le SDK 0.8.1 accepte jusqu'à 100 messages par requête HTTP, codé en dur
 * (`MessageFactory::DEFAULT_MAX_BATCH_SIZE`) : 100 `tools/call` pour une seule
 * requête comptée par la zone nginx `mcp`. Les lots ont disparu du protocole
 * à la révision 2025-06-18, la seule que le serveur négocie en handshake.
 */
final class JsonRpcBatchRefusalTest extends WebTestCase
{
    use SpeaksMcp;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        self::forgetMcpSessions(self::getContainer()->get(EntityManagerInterface::class)->getConnection());
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

        $this->assertBatchRefused($client);
    }

    /** Le refus précède la session : un lot sans `initialize` est refusé pareil. */
    public function testABatchIsRefusedWithoutASession(): void
    {
        $client = self::createClient();

        $this->postRawMcp($client, null, '[{"jsonrpc":"2.0","id":1,"method":"ping"},{"jsonrpc":"2.0","id":2,"method":"ping"}]');

        $this->assertBatchRefused($client);
    }

    /** Des blancs avant le crochet ne font pas passer le lot. */
    public function testLeadingWhitespaceDoesNotHideABatch(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $this->postRawMcp($client, $sessionId, " \r\n\t[{\"jsonrpc\":\"2.0\",\"id\":2,\"method\":\"ping\"},{\"jsonrpc\":\"2.0\",\"id\":3,\"method\":\"ping\"}]");

        $this->assertBatchRefused($client);
    }

    /** Le routeur décode `%6D` : le refus aussi (CanonicalPath, issue #77). */
    public function testAPercentEncodedPathIsRefusedToo(): void
    {
        $client = self::createClient();

        $client->request('POST', '/api/%6Dcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
        ], content: '[{"jsonrpc":"2.0","id":1,"method":"ping"},{"jsonrpc":"2.0","id":2,"method":"ping"}]');

        $this->assertBatchRefused($client);
    }

    /** Un lot d'un seul message coûte un message : le SDK le sert. */
    public function testABatchOfOneMessageIsServed(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $this->postMcp($client, $sessionId, [['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping']]);

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('batches', (string) $client->getResponse()->getContent());
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

        self::assertStringNotContainsString('batches', (string) $client->getResponse()->getContent());
        self::assertSame(-32700, $this->decodeMcpResponse($client)['error']['code']);
    }

    /** La forme des refus du transport du SDK : un identifiant illisible est omis, jamais `null`. */
    private function assertBatchRefused(KernelBrowser $client): void
    {
        self::assertResponseStatusCodeSame(400);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame([
            'jsonrpc' => '2.0',
            'error' => [
                'code' => -32600,
                'message' => 'JSON-RPC batches are not supported: send one message per request.',
            ],
        ], $this->decodeMcpResponse($client));
    }
}
