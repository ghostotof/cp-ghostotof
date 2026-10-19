<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Presentation\Tool;

use App\Portfolio\About\Application\AboutMeCardAdministratorInterface;
use App\Portfolio\About\Application\AboutSiteCardAdministratorInterface;
use App\Portfolio\About\Domain\Entity\AboutSettings;
use App\Portfolio\About\Domain\Repository\AboutSettingsRepositoryInterface;
use App\Portfolio\About\Domain\ValueObject\AboutMeCardCategory;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Tests\Mcp\Support\SpeaksMcp;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Discovery\SchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * `get_about` (spec 0007, tâche 1) : l'outil rend **exactement** ce que sert
 * `GET /api/about/{locale}` (ADR 0006 D1) — même contenu structuré, même JSON
 * dans le bloc texte —, avec un champ `null` omis comme la route l'omet.
 */
final class GetAboutToolTest extends WebTestCase
{
    use SpeaksMcp;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('DELETE FROM about_site_card');
        $connection->executeStatement('DELETE FROM about_me_card');
        $connection->executeStatement('DELETE FROM about_settings');
        self::forgetMcpSessions($connection);
        parent::tearDown();
    }

    /**
     * La langue est une chaîne dont la description cite les deux valeurs,
     * pas une énumération : une valeur inconnue doit atteindre l'outil, qui
     * répond par une erreur d'outil que le modèle peut corriger (révision MCP
     * 2025-11-25), au lieu d'un `-32602` du SDK. Décision du 2026-10-19.
     */
    public function testToolsListAnnouncesGetAboutAsReadOnlyWithAStringLocaleAndAnOutputSchema(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $tool = $this->listedMcpTool($client, $sessionId, 'get_about');

        $locale = $tool['inputSchema']['properties']['locale'];
        self::assertSame('string', $locale['type']);
        self::assertArrayNotHasKey('enum', $locale);
        self::assertStringContainsString('"fr"', $locale['description']);
        self::assertStringContainsString('"en"', $locale['description']);
        self::assertTrue($tool['annotations']['readOnlyHint']);
        self::assertSame('object', $tool['outputSchema']['type']);
    }

    /**
     * @return iterable<string, array{Locale}>
     */
    public static function locales(): iterable
    {
        yield 'fr' => [Locale::FR];
        yield 'en' => [Locale::EN];
    }

    #[DataProvider('locales')]
    public function testGetAboutReturnsExactlyWhatTheRouteServes(Locale $locale): void
    {
        $client = self::createClient();
        $this->seedAbout($client, $locale);

        $routeBody = $this->routeBody($client, '/api/about/'.$locale->value);
        $sessionId = $this->initializeMcpSession($client);
        $result = $this->callMcpTool($client, $sessionId, 'get_about', ['locale' => $locale->value]);

        self::assertFalse($result['isError'] ?? false);
        self::assertSame($routeBody, $result['structuredContent']);
        self::assertSame($routeBody, json_decode($result['content'][0]['text'], true, flags: \JSON_THROW_ON_ERROR));
        // Le provider rend un `iconKey` null pour la carte sans icône ; la
        // route l'omet (skip_null_values d'API Platform), l'outil aussi.
        self::assertArrayNotHasKey('iconKey', $routeBody['site']['cards'][1]);
    }

    public function testTheStructuredContentConformsToTheDeclaredOutputSchema(): void
    {
        $client = self::createClient();
        $this->seedAbout($client, Locale::FR);
        $sessionId = $this->initializeMcpSession($client);

        $outputSchema = $this->listedMcpTool($client, $sessionId, 'get_about')['outputSchema'];
        $result = $this->callMcpTool($client, $sessionId, 'get_about', ['locale' => 'fr']);

        self::assertSame([], new SchemaValidator()->validateAgainstJsonSchema($result['structuredContent'], $outputSchema));
    }

    public function testWithoutLocaleTheFrenchVersionIsReturned(): void
    {
        $client = self::createClient();
        $this->seedAbout($client, Locale::FR);

        $routeBody = $this->routeBody($client, '/api/about/fr');
        $sessionId = $this->initializeMcpSession($client);
        $result = $this->callMcpTool($client, $sessionId, 'get_about', []);

        self::assertSame($routeBody, $result['structuredContent']);
    }

    public function testAnUnknownLocaleIsAToolErrorTheModelCanCorrect(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $response = $this->mcpCall($client, $sessionId, 'tools/call', ['name' => 'get_about', 'arguments' => ['locale' => 'de']]);

        self::assertArrayNotHasKey('error', $response);
        self::assertTrue($response['result']['isError']);
        self::assertSame('Unknown language: use "fr" or "en".', $response['result']['content'][0]['text']);
    }

    /**
     * La route répond 404 quand la langue n'a pas de réglages « À propos » ;
     * l'outil le dit par une erreur d'outil lisible, pas par une erreur
     * interne (-32603) qui laisserait le modèle sans explication.
     */
    public function testALanguageWithoutContentIsAToolError(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $response = $this->mcpCall($client, $sessionId, 'tools/call', ['name' => 'get_about', 'arguments' => ['locale' => 'en']]);

        self::assertArrayNotHasKey('error', $response);
        self::assertTrue($response['result']['isError']);
        self::assertSame('No About content exists in this language.', $response['result']['content'][0]['text']);
    }

    /**
     * Deux cartes du site, la seconde sans icône ; une carte par catégorie
     * « à propos de moi ».
     */
    private function seedAbout(KernelBrowser $client, Locale $locale): void
    {
        $container = $client->getContainer();

        $container->get(AboutSettingsRepositoryInterface::class)->save(
            new AboutSettings($locale, 'Ce site', 'Moi', 'Techniquement', 'Humainement', 'En dehors du travail'),
        );

        $siteCards = $container->get(AboutSiteCardAdministratorInterface::class);
        $siteCards->create($locale, 'Architecture', 'Hexagonale.', 'layers');
        $siteCards->create($locale, 'Sans icône', 'Une carte sans icône.', null);

        $meCards = $container->get(AboutMeCardAdministratorInterface::class);
        $meCards->create($locale, AboutMeCardCategory::TECHNICAL, 'Développeur', 'PHP.', 'code');
        $meCards->create($locale, AboutMeCardCategory::PERSONAL, 'Curieux', 'Toujours.', 'lightbulb');
        $meCards->create($locale, AboutMeCardCategory::HOBBY, 'Musique', 'Guitare.', 'guitar');
    }
}
