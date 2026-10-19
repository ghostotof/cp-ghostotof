<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Presentation\Tool;

use App\Tests\Mcp\Support\SpeaksMcp;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Discovery\SchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Les outils du catalogue adossés à une route (spec 0007, tâche 2) : chacun
 * rend **exactement** ce que sert sa route (ADR 0006 D1), comparé dans le même
 * test, dans chaque langue que la route prend. Une route-collection est
 * enveloppée dans `{"items": …}` (la spécification MCP veut un objet), seule
 * transformation admise.
 *
 * `get_about`, premier outil du catalogue, a son propre test
 * ({@see GetAboutToolTest}) : ses cas limites (carte sans icône, langue sans
 * réglages) ne valent que pour lui.
 */
final class RouteBackedToolsTest extends WebTestCase
{
    use SpeaksMcp;

    /** Contenu à poser avant de lire la route : la commande de seed du contexte. */
    private const string CONTRIBUTIONS = 'app:contributions:seed';

    /** Tables que les seeds de ce test remplissent. */
    private const array SEEDED_TABLES = ['contribution'];

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        foreach (self::SEEDED_TABLES as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
        self::forgetMcpSessions($connection);
        parent::tearDown();
    }

    /**
     * Outil, arguments, route adossée, contenu à poser, et si la route rend
     * une collection (alors enveloppée dans `items`).
     *
     * @return iterable<string, array{string, array<string, string>, string, string, bool}>
     */
    public static function toolsAndTheirRoutes(): iterable
    {
        foreach (['fr', 'en'] as $locale) {
            yield 'list_contributions '.$locale => ['list_contributions', ['locale' => $locale], '/api/contributions/'.$locale, self::CONTRIBUTIONS, true];
        }
    }

    /**
     * @param array<string, string> $arguments
     */
    #[DataProvider('toolsAndTheirRoutes')]
    public function testTheToolReturnsExactlyWhatItsRouteServes(string $tool, array $arguments, string $routePath, string $content, bool $collection): void
    {
        $client = self::createClient();
        $this->seed($content);

        $routeBody = $this->routeBody($client, $routePath);
        self::assertNotSame([], $routeBody, 'Le seed doit donner du contenu, sinon l\'égalité ne prouve rien.');

        $sessionId = $this->initializeMcpSession($client);
        $result = $this->callMcpTool($client, $sessionId, $tool, $arguments);

        $expected = $collection ? ['items' => $routeBody] : $routeBody;
        self::assertFalse($result['isError'] ?? false);
        self::assertSame($expected, $result['structuredContent']);
        self::assertSame($expected, json_decode($result['content'][0]['text'], true, flags: \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, string> $arguments
     */
    #[DataProvider('toolsAndTheirRoutes')]
    public function testTheToolIsReadOnlyAndItsOutputConformsToItsClosedSchema(string $tool, array $arguments, string $routePath, string $content, bool $collection): void
    {
        $client = self::createClient();
        $this->seed($content);
        $sessionId = $this->initializeMcpSession($client);

        $listed = $this->listedMcpTool($client, $sessionId, $tool);
        $result = $this->callMcpTool($client, $sessionId, $tool, $arguments);

        self::assertTrue($listed['annotations']['readOnlyHint']);
        self::assertSame([], new SchemaValidator()->validateAgainstJsonSchema($result['structuredContent'], $listed['outputSchema']));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function collectionTools(): iterable
    {
        yield 'list_contributions' => ['list_contributions', '/api/contributions/fr'];
    }

    /**
     * Rien de publié : la route rend `[]`, l'outil `{"items": []}` — une
     * liste vide, pas une erreur.
     */
    #[DataProvider('collectionTools')]
    public function testEmptyContentIsAnEmptyItemsList(string $tool, string $routePath): void
    {
        $client = self::createClient();
        self::assertSame([], $this->routeBody($client, $routePath));

        $sessionId = $this->initializeMcpSession($client);
        $result = $this->callMcpTool($client, $sessionId, $tool, ['locale' => 'fr']);

        self::assertFalse($result['isError'] ?? false);
        self::assertSame(['items' => []], $result['structuredContent']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function localizedTools(): iterable
    {
        yield 'list_contributions' => ['list_contributions'];
    }

    #[DataProvider('localizedTools')]
    public function testAnUnknownLocaleIsAToolErrorTheModelCanCorrect(string $tool): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $result = $this->callMcpTool($client, $sessionId, $tool, ['locale' => 'de']);

        self::assertTrue($result['isError']);
        self::assertSame('Unknown language: use "fr" or "en".', $result['content'][0]['text']);
    }

    private function seed(string $command): void
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        $tester = new CommandTester(new Application($kernel)->find($command));
        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
    }
}
