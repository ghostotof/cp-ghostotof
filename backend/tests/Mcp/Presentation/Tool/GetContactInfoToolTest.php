<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Presentation\Tool;

use App\Tests\Mcp\Support\SpeaksMcp;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Discovery\SchemaValidator;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * `get_contact_info` (spec 0007, tâche 2) : l'adresse de la page publique du
 * formulaire de contact, et rien d'autre — ni adresse e-mail, ni nom. C'est
 * l'un des deux outils sans route adossée (ADR 0006 D1) : la route
 * `/api/contact` est une écriture.
 *
 * L'URL attendue est littérale : `APP_FRONTEND_BASE_URL` vaut
 * `http://localhost:5173` en test (`backend/.env`), la même variable que
 * `allowed_hosts`.
 */
final class GetContactInfoToolTest extends WebTestCase
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

    public function testItReturnsTheContactPageOfTheRequestedLanguageAndNothingElse(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $result = $this->callMcpTool($client, $sessionId, 'get_contact_info', ['locale' => 'en']);

        self::assertFalse($result['isError'] ?? false);
        self::assertSame(['contactPageUrl' => 'http://localhost:5173/en/contact'], $result['structuredContent']);
        self::assertSame($result['structuredContent'], json_decode($result['content'][0]['text'], true, flags: \JSON_THROW_ON_ERROR));
    }

    public function testWithoutLocaleTheFrenchPageIsReturned(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $result = $this->callMcpTool($client, $sessionId, 'get_contact_info');

        self::assertSame(['contactPageUrl' => 'http://localhost:5173/fr/contact'], $result['structuredContent']);
    }

    public function testAnUnknownLocaleIsAToolErrorTheModelCanCorrect(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $result = $this->callMcpTool($client, $sessionId, 'get_contact_info', ['locale' => 'de']);

        self::assertTrue($result['isError']);
        self::assertSame('Unknown language: use "fr" or "en".', $result['content'][0]['text']);
    }

    public function testItIsReadOnlyAndItsOutputConformsToItsClosedSchema(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $listed = $this->listedMcpTool($client, $sessionId, 'get_contact_info');
        $result = $this->callMcpTool($client, $sessionId, 'get_contact_info');

        self::assertTrue($listed['annotations']['readOnlyHint']);
        self::assertSame([], new SchemaValidator()->validateAgainstJsonSchema($result['structuredContent'], $listed['outputSchema']));
    }
}
