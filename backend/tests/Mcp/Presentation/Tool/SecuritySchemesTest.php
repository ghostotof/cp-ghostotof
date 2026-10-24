<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Presentation\Tool;

use App\Tests\Mcp\Support\SpeaksMcp;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Les `securitySchemes` de chaque outil, aux deux places (#648, ADR 0006 D3,
 * décision de l'auteur du 2026-10-24) : au premier niveau de l'outil dans
 * `tools/list`, où la référence de l'Apps SDK d'OpenAI les lit, et dans son
 * `_meta["securitySchemes"]`, le miroir de rétrocompatibilité qu'elle définit
 * aussi. Sans eux, ChatGPT n'offre pas la liaison de compte ; et il fige la
 * liste à l'approbation du connecteur, d'où leur présence dès la première
 * publication.
 *
 * Les valeurs attendues sont écrites ici, jamais lues de `src/` : un scope
 * renommé du côté du serveur doit faire rougir ce test, pas le suivre.
 */
final class SecuritySchemesTest extends WebTestCase
{
    use SpeaksMcp;

    /** Le seul outil protégé, et son scope (`McpConnection::SCOPE_NOMINATIVE_READ`). */
    private const string NOMINATIVE_TOOL = 'get_nominative_corpus';

    private const array NOMINATIVE_SCHEMES = [['type' => 'oauth2', 'scopes' => ['nominative:read']]];

    private const array PUBLIC_SCHEMES = [['type' => 'noauth']];

    private const array CLIENT_INFO = ['name' => 'phpunit', 'version' => '1.0.0'];

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        self::forgetMcpSessions(self::getContainer()->get(EntityManagerInterface::class)->getConnection());
        parent::tearDown();
    }

    #[DataProvider('eras')]
    public function testEveryListedToolDeclaresItsSchemesAtBothPlaces(string $era): void
    {
        $client = self::createClient();
        $tools = $this->listedTools($client, $era);

        $names = array_column($tools, 'name');
        self::assertContains(self::NOMINATIVE_TOOL, $names, 'L\'outil nominatif est toujours listé (ADR 0006 D3).');
        self::assertContains('get_about', $names);

        foreach ($tools as $tool) {
            $expected = self::NOMINATIVE_TOOL === $tool['name'] ? self::NOMINATIVE_SCHEMES : self::PUBLIC_SCHEMES;
            self::assertSame($expected, $tool['securitySchemes'] ?? null, \sprintf('« %s » : securitySchemes au premier niveau.', $tool['name']));
            self::assertSame($expected, $tool['_meta']['securitySchemes'] ?? null, \sprintf('« %s » : _meta.securitySchemes.', $tool['name']));
        }
    }

    /**
     * La révision sans session découvre le serveur par `server/discover`, qui
     * ne liste aucun outil en 0.8.1 : les outils s'y découvrent par
     * `tools/list`, couvert ci-dessus. Le jour où une montée du SDK y met la
     * liste, ce test rougit, et la liste doit y porter ses schémas aussi.
     */
    public function testServerDiscoverListsNoTool(): void
    {
        $client = self::createClient();
        $this->postModernMcp($client, 'server/discover', clientInfo: self::CLIENT_INFO);

        $result = $this->decodeMcpResponse($client)['result'] ?? null;
        self::assertIsArray($result, 'server/discover a répondu une erreur.');
        self::assertArrayNotHasKey('tools', $result);
        self::assertStringNotContainsString(self::NOMINATIVE_TOOL, json_encode($result, \JSON_THROW_ON_ERROR));
    }

    /**
     * L'appel passe par `getTool()`, celui du registre décoré : la décoration
     * de `tools/list` ne change rien à ce qu'un outil rend, dans les deux ères.
     * `get_contact_info` plutôt que `get_about` : sa réponse ne dépend d'aucun
     * contenu en base, l'égalité se lit sans jeu de données.
     */
    #[DataProvider('eras')]
    public function testCallsStillReachTheTools(string $era): void
    {
        $client = self::createClient();
        $params = ['name' => 'get_contact_info', 'arguments' => ['locale' => 'en']];
        if ('session' === $era) {
            $result = $this->callMcpTool($client, $this->initializeMcpSession($client), 'get_contact_info', ['locale' => 'en']);
        } else {
            $this->postModernMcp($client, 'tools/call', $params, self::CLIENT_INFO);
            $result = $this->decodeMcpResponse($client)['result'] ?? null;
            self::assertIsArray($result, 'tools/call a répondu une erreur de protocole.');
        }

        self::assertFalse($result['isError'] ?? false);
        // `APP_FRONTEND_BASE_URL` vaut `http://localhost:5173` en test (backend/.env).
        self::assertSame(['contactPageUrl' => 'http://localhost:5173/en/contact'], $result['structuredContent'] ?? null);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function eras(): iterable
    {
        yield 'avec session (2025-06-18)' => ['session'];
        yield 'sans session (2026-07-28)' => ['modern'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listedTools(KernelBrowser $client, string $era): array
    {
        if ('session' === $era) {
            $response = $this->mcpCall($client, $this->initializeMcpSession($client), 'tools/list');
        } else {
            $this->postModernMcp($client, 'tools/list', clientInfo: self::CLIENT_INFO);
            $response = $this->decodeMcpResponse($client);
        }

        self::assertIsArray($response['result'] ?? null, 'tools/list a répondu une erreur de protocole.');
        self::assertIsArray($response['result']['tools'] ?? null);
        self::assertArrayNotHasKey('nextCursor', $response['result'], 'Une seconde page échapperait au test.');

        /** @var list<array<string, mixed>> $tools */
        $tools = $response['result']['tools'];

        return $tools;
    }
}
