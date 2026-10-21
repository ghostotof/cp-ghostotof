<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Mcp\Presentation\Prompt\PromptRole;
use App\Mcp\Presentation\Tool\AccessTier;
use App\Mcp\Presentation\Tool\BackedByRoute;
use App\Tests\Support\RepositoryFiles;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Prompt;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * La page « Brancher votre assistant » (#645) recopie le catalogue du serveur
 * MCP : les noms d'outils par palier, le prompt, ses arguments et la borne de
 * `role` sont écrits à la main dans le contenu du front
 * (`frontend/src/infrastructure/portfolio/content/{fr,en}.ts`). Avant #673,
 * un outil renommé, ajouté ou changé de palier côté backend ne faisait rien
 * rougir : la page promettait un outil que le serveur ne sert pas.
 *
 * Ce test lit le **registre réel** du serveur `portfolio` et le texte de
 * chaque langue, et exige l'égalité. Le contenu du front est monté en lecture
 * seule dans le conteneur de dev (docker-compose.yml) ; la CI extrait tout le
 * dépôt. La lecture est textuelle (le fichier est du TypeScript) : elle part
 * de la clé `connectAssistant`, seule section qui nomme un outil.
 */
final class ConnectAssistantPageMirrorsCatalogueTest extends KernelTestCase
{
    /**
     * L'outil que la page ne range sous aucun palier : listé par le serveur
     * mais réservé à la connexion MCP (ADR 0006 D3). La page le nomme dans
     * « ce que le serveur ne sert jamais ».
     */
    private const string CONNECTION_ONLY_TOOL = 'get_nominative_corpus';

    /**
     * Le palier des outils sans route qui le servent sans connexion
     * (`McpExposureSymmetryTest::TOOLS_WITHOUT_ROUTE`, hors corpus) : l'adresse
     * de la page de contact est publique.
     */
    private const array TIER_OF_TOOLS_WITHOUT_ROUTE = [
        'get_contact_info' => AccessTier::ANONYMOUS,
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function locales(): iterable
    {
        yield 'fr' => ['fr'];
        yield 'en' => ['en'];
    }

    #[DataProvider('locales')]
    public function testEachTierListsExactlyTheToolsTheServerServesAtThatTier(string $locale): void
    {
        $section = $this->connectAssistantSection($locale);

        foreach ([AccessTier::ANONYMOUS, AccessTier::BASE] as $tier) {
            self::assertSame(
                $this->toolsServedAt($tier),
                self::sorted(self::toolsListedUnder($section, $tier)),
                \sprintf('%s.ts : la liste du palier « %s » diffère du registre du serveur MCP.', $locale, $tier->value),
            );
        }
    }

    #[DataProvider('locales')]
    public function testTheToolReservedToTheConnectionIsNamedOutsideTheTiers(string $locale): void
    {
        $section = $this->connectAssistantSection($locale);

        self::assertArrayHasKey(self::CONNECTION_ONLY_TOOL, $this->registeredTools(), 'L\'outil réservé à la connexion n\'est plus au registre : ce test est à revoir.');
        self::assertStringContainsString('`'.self::CONNECTION_ONLY_TOOL.'`', $section, \sprintf('%s.ts ne nomme plus %s.', $locale, self::CONNECTION_ONLY_TOOL));
    }

    #[DataProvider('locales')]
    public function testThePromptIsTheServersWithItsArgumentsAndBound(string $locale): void
    {
        $section = $this->connectAssistantSection($locale);
        $prompts = $this->registeredPrompts();

        self::assertSame(1, preg_match_all("/\\bname: '([a-z_]+)'/", $section, $names), \sprintf('%s.ts : la page doit nommer un seul prompt.', $locale));
        self::assertSame(array_keys($prompts), $names[1], \sprintf('%s.ts : le prompt nommé diffère du registre.', $locale));

        $arguments = self::argumentsLine($section, $locale);
        foreach ($prompts[$names[1][0]]->arguments ?? [] as $argument) {
            self::assertStringContainsString('`'.$argument->name.'`', $arguments, \sprintf('%s.ts : l\'argument « %s » du prompt n\'est pas nommé.', $locale, $argument->name));
        }

        self::assertSame(1, preg_match('/(\d+) (?:caractères au plus|characters at most)/u', $arguments, $bound), \sprintf('%s.ts : la borne de `role` n\'est plus écrite.', $locale));
        self::assertSame((string) PromptRole::MAX_CHARACTERS, $bound[1], \sprintf('%s.ts : la borne publiée diffère de PromptRole::MAX_CHARACTERS.', $locale));
    }

    /**
     * Les noms d'outils écrits sous le palier, entre son `id` et le palier
     * suivant (ou le prompt).
     *
     * @return list<string>
     */
    private static function toolsListedUnder(string $section, AccessTier $tier): array
    {
        $pattern = \sprintf("/id: '%s',(?<block>.*?)(?:id: '|prompt: \\{)/s", $tier->value);
        self::assertSame(1, preg_match($pattern, $section, $match), \sprintf('Palier « %s » introuvable dans la page.', $tier->value));
        preg_match_all("/\\btool: '([a-z_]+)'/", $match['block'], $tools);

        return $tools[1];
    }

    private static function argumentsLine(string $section, string $locale): string
    {
        self::assertSame(1, preg_match("/\\barguments:\\s*'(?<line>[^']*)'/", $section, $match), \sprintf('%s.ts : la ligne des arguments du prompt est introuvable.', $locale));

        return $match['line'];
    }

    private function connectAssistantSection(string $locale): string
    {
        $content = RepositoryFiles::read(RepositoryFiles::repository(\sprintf('frontend/src/infrastructure/portfolio/content/%s.ts', $locale)));
        $start = strpos($content, 'connectAssistant: {');
        self::assertNotFalse($start, \sprintf('%s.ts : section connectAssistant introuvable.', $locale));

        return substr($content, $start);
    }

    /**
     * Les outils que le serveur sert sans connexion à ce palier, triés.
     *
     * @return list<string>
     */
    private function toolsServedAt(AccessTier $tier): array
    {
        $served = [];
        foreach ($this->registeredTools() as $name => $class) {
            if (self::CONNECTION_ONLY_TOOL === $name) {
                continue;
            }

            $attributes = (new ReflectionClass($class))->getAttributes(BackedByRoute::class);
            $toolTier = [] === $attributes ? self::TIER_OF_TOOLS_WITHOUT_ROUTE[$name] ?? null : $attributes[0]->newInstance()->tier;
            self::assertNotNull($toolTier, \sprintf('L\'outil « %s » n\'a ni route ni palier connu de ce test.', $name));

            if ($tier === $toolTier) {
                $served[] = $name;
            }
        }

        self::assertNotEmpty($served, \sprintf('Aucun outil au palier « %s » : le test ne vérifie plus rien.', $tier->value));

        return self::sorted($served);
    }

    /**
     * @return array<string, class-string> nom => classe de l'outil
     */
    private function registeredTools(): array
    {
        $registry = $this->registry();

        $tools = [];
        foreach ($registry->getTools() as $name => $tool) {
            self::assertIsString($name);
            $handler = $registry->getTool($name)->handler;
            self::assertIsArray($handler);
            self::assertIsString($handler[0]);
            self::assertTrue(class_exists($handler[0]));
            $tools[$name] = $handler[0];
        }

        return $tools;
    }

    /**
     * @return array<string, Prompt>
     */
    private function registeredPrompts(): array
    {
        $prompts = [];
        foreach ($this->registry()->getPrompts() as $name => $prompt) {
            self::assertIsString($name);
            self::assertInstanceOf(Prompt::class, $prompt);
            $prompts[$name] = $prompt;
        }
        self::assertNotEmpty($prompts);

        return $prompts;
    }

    /**
     * Le registre du serveur `portfolio`, rempli à la construction du serveur
     * comme à la première requête.
     */
    private function registry(): RegistryInterface
    {
        $container = self::getContainer();
        $container->get('mcp.server.portfolio.builder')->build();
        return $container->get('mcp.server.portfolio.registry');
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private static function sorted(array $names): array
    {
        sort($names);

        return $names;
    }
}
