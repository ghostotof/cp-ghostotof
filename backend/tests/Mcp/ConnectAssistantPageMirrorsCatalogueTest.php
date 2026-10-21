<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Mcp\Presentation\Prompt\PromptRole;
use App\Mcp\Presentation\Tool\AccessTier;
use App\Tests\Mcp\Support\ReadsPortfolioRegistry;
use App\Tests\Support\RepositoryFiles;
use PHPUnit\Framework\Attributes\DataProvider;
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
 * chaque langue, et exige l'égalité dans les deux sens : chaque outil du
 * registre est sur la page, chaque outil de la page au registre, à son palier.
 * Le contenu du front est monté en lecture seule dans le conteneur de dev
 * (docker-compose.yml) ; la CI extrait tout le dépôt. La lecture est
 * textuelle (le fichier est du TypeScript), bornée à deux blocs de la clé
 * `connectAssistant` : « ce que le serveur sert » (`served`, jusqu'à
 * `neverServed`) et « ce qu'il ne sert jamais » (`neverServed`, jusqu'à
 * `privacy`) — les clients, plus bas, portent aussi des `name:`.
 */
final class ConnectAssistantPageMirrorsCatalogueTest extends KernelTestCase
{
    use ReadsPortfolioRegistry;

    /**
     * L'outil que la page ne range sous aucun palier : listé par le serveur
     * mais réservé à la connexion MCP (ADR 0006 D3). La page le nomme dans
     * « ce que le serveur ne sert jamais ».
     */
    private const string CONNECTION_ONLY_TOOL = 'get_nominative_corpus';

    /**
     * Le palier des autres outils sans route : l'adresse de la page de contact
     * est publique. Avec {@see self::CONNECTION_ONLY_TOOL}, exactement la liste
     * fermée `McpExposureSymmetryTest::TOOLS_WITHOUT_ROUTE` (vérifié).
     */
    private const array TIER_OF_TOOLS_WITHOUT_ROUTE = [
        'get_contact_info' => AccessTier::ANONYMOUS,
    ];

    /** Les paliers que la page présente, servis sans connexion (ADR 0006 D2). */
    private const array PAGE_TIERS = [AccessTier::ANONYMOUS, AccessTier::BASE];

    /**
     * @return iterable<string, array{string}>
     */
    public static function locales(): iterable
    {
        yield 'fr' => ['fr'];
        yield 'en' => ['en'];
    }

    public function testTheToolsWithoutRouteAreThoseOfTheExposureTest(): void
    {
        $here = [...array_keys(self::TIER_OF_TOOLS_WITHOUT_ROUTE), self::CONNECTION_ONLY_TOOL];

        self::assertSame(
            $this->sorted(array_keys(McpExposureSymmetryTest::TOOLS_WITHOUT_ROUTE)),
            $this->sorted($here),
            'La liste fermée des outils sans route a changé : ranger le nouvel outil ici, à son palier ou comme réservé à la connexion.',
        );
    }

    #[DataProvider('locales')]
    public function testEachTierListsExactlyTheToolsTheServerServesAtThatTier(string $locale): void
    {
        $served = $this->block($locale, 'served', 'neverServed');
        $tiers = $this->toolTiers();

        foreach (self::PAGE_TIERS as $tier) {
            $expected = $this->sorted(array_keys(array_filter($tiers, static fn (?AccessTier $toolTier): bool => $tier === $toolTier)));
            self::assertNotEmpty($expected, \sprintf('Aucun outil au palier « %s » : le test ne vérifie plus rien.', $tier->value));

            self::assertSame(
                $expected,
                $this->sorted($this->toolsListedUnder($served, $tier)),
                \sprintf('%s.ts : la liste du palier « %s » diffère du registre du serveur MCP.', $locale, $tier->value),
            );
        }
    }

    /**
     * Un outil d'un palier que la page ne présente pas (le palier de
     * confiance) n'y serait exigé nulle part par le test précédent : celui-ci
     * veut chaque outil du registre sur la page, sous un palier ou comme
     * réservé à la connexion.
     */
    #[DataProvider('locales')]
    public function testEveryToolOfTheServerIsOnThePage(string $locale): void
    {
        $served = $this->block($locale, 'served', 'neverServed');

        $onThePage = [self::CONNECTION_ONLY_TOOL];
        foreach (self::PAGE_TIERS as $tier) {
            $onThePage = [...$onThePage, ...$this->toolsListedUnder($served, $tier)];
        }

        self::assertSame(
            $this->sorted(array_keys($this->toolTiers())),
            $this->sorted($onThePage),
            \sprintf('%s.ts : un outil du serveur MCP n\'est pas présenté par la page.', $locale),
        );
    }

    #[DataProvider('locales')]
    public function testTheToolReservedToTheConnectionIsNamedInWhatTheServerNeverServes(string $locale): void
    {
        self::assertArrayHasKey(self::CONNECTION_ONLY_TOOL, $this->toolTiers(), 'L\'outil réservé à la connexion n\'est plus au registre : ce test est à revoir.');
        self::assertStringContainsString(
            '`'.self::CONNECTION_ONLY_TOOL.'`',
            $this->block($locale, 'neverServed', 'privacy'),
            \sprintf('%s.ts ne nomme plus %s dans « ce que le serveur ne sert jamais ».', $locale, self::CONNECTION_ONLY_TOOL),
        );
    }

    #[DataProvider('locales')]
    public function testThePromptIsTheServersWithItsArgumentsAndBound(string $locale): void
    {
        $served = $this->block($locale, 'served', 'neverServed');
        $prompts = $this->registeredPrompts();

        self::assertSame(1, preg_match_all("/\\bname: '([a-z_]+)'/", $served, $names), \sprintf('%s.ts : la page doit nommer un seul prompt.', $locale));
        self::assertSame(array_keys($prompts), $names[1], \sprintf('%s.ts : le prompt nommé diffère du registre.', $locale));

        $arguments = $this->argumentsLine($served, $locale);
        foreach ($prompts[$names[1][0]]->arguments ?? [] as $argument) {
            self::assertStringContainsString('`'.$argument->name.'`', $arguments, \sprintf('%s.ts : l\'argument « %s » du prompt n\'est pas nommé.', $locale, $argument->name));
        }

        self::assertSame(1, preg_match('/(\d+) (?:caractères au plus|characters at most)/u', $arguments, $bound), \sprintf('%s.ts : la borne de `role` n\'est plus écrite.', $locale));
        self::assertSame((string) PromptRole::MAX_CHARACTERS, $bound[1], \sprintf('%s.ts : la borne publiée diffère de PromptRole::MAX_CHARACTERS.', $locale));
    }

    /**
     * Le palier de chaque outil du registre : celui que déclare sa route, ou
     * celui de la liste des outils sans route ; `null` pour l'outil réservé à
     * la connexion.
     *
     * @return array<string, ?AccessTier>
     */
    private function toolTiers(): array
    {
        $tiers = [];
        foreach ($this->declaredToolRoutes() as $name => $route) {
            if (self::CONNECTION_ONLY_TOOL === $name) {
                $tiers[$name] = null;

                continue;
            }

            $tier = $route->tier ?? self::TIER_OF_TOOLS_WITHOUT_ROUTE[$name] ?? null;
            self::assertNotNull($tier, \sprintf('L\'outil « %s » n\'a ni route ni palier connu de ce test.', $name));
            $tiers[$name] = $tier;
        }

        return $tiers;
    }

    /**
     * Les noms d'outils écrits sous le palier, entre son `id` et le palier
     * suivant (ou le prompt).
     *
     * @return list<string>
     */
    private function toolsListedUnder(string $served, AccessTier $tier): array
    {
        $pattern = \sprintf("/id: '%s',(?<block>.*?)(?:id: '|prompt: \\{)/s", $tier->value);
        self::assertSame(1, preg_match($pattern, $served, $match), \sprintf('Palier « %s » introuvable dans la page.', $tier->value));
        preg_match_all("/\\btool: '([a-z_]+)'/", $match['block'], $tools);

        return $tools[1];
    }

    private function argumentsLine(string $served, string $locale): string
    {
        self::assertSame(1, preg_match("/\\barguments:\\s*'(?<line>[^']*)'/", $served, $match), \sprintf('%s.ts : la ligne des arguments du prompt est introuvable.', $locale));

        return $match['line'];
    }

    /**
     * Le texte de la clé `$key` de `connectAssistant`, jusqu'à la clé
     * `$next` du même niveau (quatre espaces d'indentation).
     */
    private function block(string $locale, string $key, string $next): string
    {
        $content = RepositoryFiles::read(RepositoryFiles::repository(\sprintf('frontend/src/infrastructure/portfolio/content/%s.ts', $locale)));
        $section = strpos($content, "\n  connectAssistant: {\n");
        self::assertNotFalse($section, \sprintf('%s.ts : section connectAssistant introuvable.', $locale));

        $start = strpos($content, \sprintf("\n    %s: {\n", $key), $section);
        self::assertNotFalse($start, \sprintf('%s.ts : clé connectAssistant.%s introuvable.', $locale, $key));
        $end = strpos($content, \sprintf("\n    %s: {\n", $next), $start);
        self::assertNotFalse($end, \sprintf('%s.ts : clé connectAssistant.%s introuvable après %s.', $locale, $next, $key));

        return substr($content, $start, $end - $start);
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private function sorted(array $names): array
    {
        sort($names);

        return $names;
    }
}
