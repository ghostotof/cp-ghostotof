<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Presentation\Tool;

use App\Portfolio\Experience\Application\ExperienceTechnologyRegistrarInterface;
use App\Portfolio\Experience\Domain\ValueObject\ExperienceYears;
use App\Portfolio\Experience\Domain\ValueObject\TechnologyName;
use App\Portfolio\Watch\Domain\Entity\WatchSnapshot;
use App\Portfolio\Watch\Domain\Repository\WatchSnapshotRepositoryInterface;
use App\Portfolio\Watch\Domain\ValueObject\SnapshotSourceStatus;
use App\Portfolio\Watch\Domain\ValueObject\WatchSnapshotType;
use App\Tests\Mcp\Support\SpeaksMcp;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Discovery\SchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
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

    /*
     * Contenu à poser avant de lire la route : la commande de seed du
     * contexte quand il en a une, sinon un jeu posé par le test.
     */
    private const string CONTRIBUTIONS = 'app:contributions:seed';

    private const string INCIDENTS = 'app:incidents:seed';

    private const string QUALITY = 'app:quality:seed';

    private const string TECHNOLOGIES = 'technologies';

    private const string WATCH_RELEASE_CYCLES = 'watch-release-cycles';

    /** Tables que les seeds de ce test remplissent. */
    private const array SEEDED_TABLES = ['contribution', 'incident', 'quality_principle', 'quality_trait', 'experience_technology', 'watch_snapshot'];

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
            yield 'list_incidents '.$locale => ['list_incidents', ['locale' => $locale], '/api/incidents/'.$locale, self::INCIDENTS, true];
            yield 'get_quality '.$locale => ['get_quality', ['locale' => $locale], '/api/quality/'.$locale, self::QUALITY, false];
        }

        // Routes sans langue : l'outil ne déclare pas de paramètre.
        yield 'get_technical_background' => ['get_technical_background', [], '/api/experience/technologies', self::TECHNOLOGIES, true];
        yield 'get_tech_watch' => ['get_tech_watch', [], '/api/watch', self::WATCH_RELEASE_CYCLES, false];
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
        // `structuredContent` voyage dans la réponse JSON-RPC, que le SDK
        // encode sans JSON_PRESERVE_ZERO_FRACTION : les années `1.0` de la
        // route y deviennent `1`, le même nombre JSON. Il est donc comparé en
        // valeur JSON ; le bloc texte, que l'outil encode lui-même, l'est à
        // l'identique.
        self::assertSame($this->asJsonValue($expected), $result['structuredContent']);
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
        yield 'list_incidents' => ['list_incidents', '/api/incidents/fr'];
        yield 'get_technical_background' => ['get_technical_background', '/api/experience/technologies'];
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
        $result = $this->callMcpTool($client, $sessionId, $tool, $this->toolTakesALocale($client, $sessionId, $tool) ? ['locale' => 'fr'] : []);

        self::assertFalse($result['isError'] ?? false);
        self::assertSame(['items' => []], $result['structuredContent']);
    }

    /**
     * Une installation dont l'image n'a pas de manifeste (l'état normal d'un
     * conteneur de dev) n'a jamais analysé ses paquets : la route le dit
     * (volet vulnérabilités « non analysé »), l'outil rend cette sortie telle
     * quelle — ce n'est pas une erreur, et surtout pas « 0 vulnérabilité ».
     */
    public function testWithoutAManifestTheTechWatchIsTheRouteAsIsNotAnError(): void
    {
        $client = self::createClient();
        $this->seed(self::WATCH_RELEASE_CYCLES);
        $routeBody = $this->routeBody($client, '/api/watch');
        self::assertNull($routeBody['vulnerabilities']['packagesScanned']);

        $sessionId = $this->initializeMcpSession($client);
        $result = $this->callMcpTool($client, $sessionId, 'get_tech_watch');

        self::assertFalse($result['isError'] ?? false);
        self::assertSame($routeBody, $result['structuredContent']);
    }

    /**
     * Une route sans segment de langue donne un outil sans paramètre : le
     * modèle ne doit pas croire qu'une traduction existe.
     *
     * @return iterable<string, array{string}>
     */
    public static function toolsWithoutLocale(): iterable
    {
        yield 'get_technical_background' => ['get_technical_background'];
        yield 'get_tech_watch' => ['get_tech_watch'];
    }

    #[DataProvider('toolsWithoutLocale')]
    public function testAToolWhoseRouteTakesNoLanguageDeclaresNoParameter(string $tool): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        self::assertFalse($this->toolTakesALocale($client, $sessionId, $tool));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function localizedTools(): iterable
    {
        yield 'list_contributions' => ['list_contributions'];
        yield 'list_incidents' => ['list_incidents'];
        yield 'get_quality' => ['get_quality'];
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

    /**
     * La valeur JSON telle qu'un encodeur sans drapeau la rend : un flottant
     * entier (`1.0`) y devient `1`, rien d'autre ne change.
     *
     * @param array<mixed> $value
     */
    private function asJsonValue(array $value): mixed
    {
        return json_decode(json_encode($value, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function toolTakesALocale(KernelBrowser $client, string $sessionId, string $tool): bool
    {
        $properties = $this->listedMcpTool($client, $sessionId, $tool)['inputSchema']['properties'] ?? [];

        return \is_array($properties) && \array_key_exists('locale', $properties);
    }

    private function seed(string $content): void
    {
        match ($content) {
            self::TECHNOLOGIES => $this->registerTechnologies(),
            self::WATCH_RELEASE_CYCLES => $this->saveReleaseCyclesSnapshot(),
            default => $this->runSeedCommand($content),
        };
    }

    private function runSeedCommand(string $command): void
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        $tester = new CommandTester(new Application($kernel)->find($command));
        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
    }

    /**
     * Une technologie complète, une secondaire sans icône ni technologie
     * liée : la route omet ces champs nuls, l'outil doit les omettre aussi.
     */
    private function registerTechnologies(): void
    {
        $registrar = self::getContainer()->get(ExperienceTechnologyRegistrarInterface::class);
        $registrar->register(TechnologyName::fromString('Symfony'), ExperienceYears::fromFloat(8.5), 'symfony', 'PHP');
        $registrar->register(TechnologyName::fromString('Rancher'), ExperienceYears::fromFloat(1.0), null, null, true);
    }

    /**
     * Un relevé des cycles de vie tel que le rafraîchisseur l'écrit, sans
     * relevé de vulnérabilités (pas de manifeste).
     */
    private function saveReleaseCyclesSnapshot(): void
    {
        self::getContainer()->get(WatchSnapshotRepositoryInterface::class)->save(new WatchSnapshot(
            WatchSnapshotType::RELEASE_CYCLES,
            ['products' => [[
                'slug' => 'php',
                'label' => 'PHP',
                'version' => '8.5.9',
                'status' => 'supported',
                'cycle' => '8.5',
                'endOfActiveSupportFrom' => '2027-12-31',
                'eolFrom' => '2029-12-31',
                'latestVersion' => '8.5.10',
                'hasNewerPatch' => true,
                'documentationUrl' => 'https://endoflife.date/php',
            ]]],
            new DateTimeImmutable('2026-09-07 04:41:00', new DateTimeZone('UTC')),
            SnapshotSourceStatus::OK,
        ));
    }
}
