<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Presentation\Tool;

use App\Tests\Mcp\Support\SpeaksMcp;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * `get_nominative_corpus` (spec 0007, tâche 2) : **toujours listé**, sa
 * description dit qu'il exige une connexion MCP (ADR 0006 D3) — c'est ce sur
 * quoi s'appuie l'authentification différée de Claude et de ChatGPT. Sans
 * connexion, un appel non marqué reçoit le 401 différé avant le SDK (#648,
 * DeferredAuthorizationListenerTest) et n'atteint jamais l'outil ; un appel
 * marqué `openai/` (ChatGPT) l'atteint, et rend une erreur d'outil, rien du
 * corpus.
 */
final class GetNominativeCorpusToolTest extends WebTestCase
{
    use SpeaksMcp;

    /** Ce qu'un appel de ChatGPT porte dans son `_meta` (préfixe `openai/`). */
    private const array OPENAI_MARKER = ['openai/subject' => 'subject-1'];

    private const string CONNECTION_REQUIRED = 'An MCP connection is required to read the nominative corpus, and this server does not offer one yet.';

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        self::forgetMcpSessions(self::getContainer()->get(EntityManagerInterface::class)->getConnection());
        parent::tearDown();
    }

    public function testItIsListedAndSaysItRequiresAnMcpConnection(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $tool = $this->listedMcpTool($client, $sessionId, 'get_nominative_corpus');

        self::assertStringContainsString('Requires an MCP connection', $tool['description']);
        self::assertTrue($tool['annotations']['readOnlyHint']);
        self::assertSame('string', $tool['inputSchema']['properties']['locale']['type']);
    }

    /**
     * L'erreur d'outil est le seul contenu : pas de `structuredContent`, un
     * seul bloc, le message littéral — aucun fragment du corpus ne peut s'y
     * glisser.
     */
    public function testAMarkedAnonymousCallIsAToolErrorThatServesNothingOfTheCorpus(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $response = $this->mcpCall($client, $sessionId, 'tools/call', [
            'name' => 'get_nominative_corpus',
            'arguments' => ['locale' => 'fr'],
            '_meta' => self::OPENAI_MARKER,
        ]);

        self::assertIsArray($response['result'] ?? null, 'tools/call a répondu une erreur de protocole.');
        $result = $response['result'];
        self::assertTrue($result['isError']);
        self::assertArrayNotHasKey('structuredContent', $result);
        self::assertSame([['type' => 'text', 'text' => self::CONNECTION_REQUIRED]], $result['content']);
    }

    /** Sans marqueur, l'appel anonyme reçoit le défi avant le SDK : l'outil ne tourne pas. */
    public function testAnUnmarkedAnonymousCallNeverReachesTheTool(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $this->postMcp($client, $sessionId, [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'get_nominative_corpus', 'arguments' => ['locale' => 'fr']],
        ]);

        self::assertResponseStatusCodeSame(401);
        self::assertSame('', (string) $client->getResponse()->getContent());
        self::assertResponseHasHeader('WWW-Authenticate');
    }
}
