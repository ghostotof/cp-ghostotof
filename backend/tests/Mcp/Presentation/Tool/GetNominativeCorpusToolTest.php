<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Presentation\Tool;

use App\Tests\Mcp\Support\SpeaksMcp;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * `get_nominative_corpus` en phase 1 (spec 0007, tâche 2) : **toujours
 * listé**, sa description dit qu'il exige une connexion MCP (ADR 0006 D3) —
 * c'est ce qui permettra en phase 2 l'authentification différée de Claude et
 * de ChatGPT. Sans connexion, son appel rend une erreur d'outil, et rien du
 * corpus.
 */
final class GetNominativeCorpusToolTest extends WebTestCase
{
    use SpeaksMcp;

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
    public function testAnAnonymousCallIsAToolErrorThatServesNothingOfTheCorpus(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $result = $this->callMcpTool($client, $sessionId, 'get_nominative_corpus', ['locale' => 'fr']);

        self::assertTrue($result['isError']);
        self::assertArrayNotHasKey('structuredContent', $result);
        self::assertSame([['type' => 'text', 'text' => self::CONNECTION_REQUIRED]], $result['content']);
    }
}
