<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Ai\Assistant\Application\Corpus\PdfTextExtractorInterface;
use App\Ai\Assistant\Infrastructure\Pdf\ExtractedTextNormalizer;
use App\Ai\Assistant\Infrastructure\Pdf\PopplerPdfTextExtractor;
use App\Tests\Mcp\Support\AuthorMentions;
use App\Tests\Mcp\Support\SpeaksMcp;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Garde D8 (ADR 0006, spec 0007 tâche 3) : rien de ce que le serveur MCP dit
 * de lui-même ne nomme l'auteur du site.
 *
 * Ce que le serveur dit de lui-même est lu par n'importe quel client, donc au
 * palier anonyme : la réponse à `initialize` (`serverInfo`, `instructions`),
 * `tools/list` (descriptions, titres, schémas d'entrée et de sortie),
 * `prompts/list` (descriptions des arguments comprises), le message de
 * `prompts/get` dans chaque langue, et les listes de ressources. Le test les
 * lit **par HTTP, sur le serveur réel**, pas sur une déclaration : un outil,
 * un argument ou un prompt ajouté demain y passe sans qu'on l'inscrive ici.
 * Le contenu que rendent les outils n'en fait pas partie : c'est celui des
 * routes, public par décision d'auteur (D1).
 *
 * Le nom vient de la configuration de test (`MCP_D8_AUTHOR_NAME`,
 * `phpunit.dist.xml`), jamais du test : fictif en local, le vrai en CI par un
 * secret. Le CV est celui que sert `GET /api/cv` (`app.cv_file_path`) : sans
 * texte en CI (`dummy.pdf`), le vrai sur un poste qui le porte. Ni l'un ni
 * l'autre n'est jamais cité dans un message d'échec ({@see AuthorMentions}).
 */
final class AuthorNeverNamedTest extends WebTestCase
{
    use SpeaksMcp;

    /**
     * Les exceptions acceptées à D8, **recopiées de la liste fermée de l'ADR
     * 0006** : chacune décidée par l'auteur, admise à son chemin et pour sa
     * valeur exactes, nulle part ailleurs. Une exception nouvelle s'ajoute
     * d'abord à l'ADR, puis ici.
     */
    private const array ADMITTED = [
        // 2026-10-19, relecture de la tâche 1 (#640) : le nom reprend celui du
        // domaine, déjà public. Pour ce serveur uniquement.
        'initialize.serverInfo.name' => 'cp-ghostotof',
    ];

    /** Le poste passé à `prompts/get` : un texte neutre, le test lit ce que le serveur y ajoute. */
    private const string NEUTRAL_ROLE = 'Backend developer';

    private const string FICTIONAL_CV = __DIR__.'/../Ai/Assistant/Infrastructure/Pdf/Fixtures/cv-fictif.pdf';

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        self::forgetMcpSessions(self::getContainer()->get(EntityManagerInterface::class)->getConnection());
        parent::tearDown();
    }

    public function testNothingTheServerSaysOfItselfNamesTheAuthor(): void
    {
        $client = self::createClient();
        $published = $this->publishedBy($client);
        $cvText = self::getContainer()->get(PdfTextExtractorInterface::class)->extract();

        self::assertSame([], AuthorMentions::of($this->authorName(), $cvText)->in($published, self::ADMITTED));
    }

    /**
     * Une exception que le serveur ne publie plus telle quelle est une entrée
     * morte : elle admettrait demain une valeur que personne n'a décidée.
     */
    public function testEveryAdmittedExceptionIsPublishedExactlyAsTheAdrWritesIt(): void
    {
        $published = $this->publishedBy(self::createClient());

        foreach (self::ADMITTED as $path => $value) {
            self::assertSame($value, $this->at($published, $path), $path);
        }
    }

    /**
     * Critère de la tâche : la garde passe au rouge quand une description
     * d'outil reçoit le nom de test — entier, ou son dernier mot seul, en
     * capitales.
     */
    public function testTheTestNameInAToolDescriptionTurnsTheGuardRed(): void
    {
        $published = $this->publishedBy(self::createClient());
        $name = $this->authorName();
        $words = preg_split('/\s+/', trim($name));
        self::assertIsArray($words);
        $published = self::appended($published, ['tools/list', 'tools', 0, 'description'], ' Written by '.$name.'.');
        $published = self::appended($published, ['tools/list', 'tools', 1, 'description'], ' Ask '.mb_strtoupper((string) array_last($words)).'.');

        $violations = AuthorMentions::of($name, null)->in($published, self::ADMITTED);

        self::assertContains('tools/list.tools[0].description : nom de l\'auteur (terme n°0)', $violations);
        self::assertNotSame([], preg_grep('/^tools\/list\.tools\[1\]\.description : nom de l\'auteur/', $violations));
    }

    /** L'exception n'admet que sa valeur : un autre nom à son chemin est lu comme le reste. */
    public function testTheNameAtAnAdmittedPathIsStillRefused(): void
    {
        $published = $this->publishedBy(self::createClient());
        $published['initialize']['serverInfo']['name'] = $this->authorName();

        $violations = AuthorMentions::of($this->authorName(), null)->in($published, self::ADMITTED);

        self::assertContains('initialize.serverInfo.name : nom de l\'auteur (terme n°0)', $violations);
    }

    /**
     * Un fragment du CV dans un message de prompt fait échouer la garde. Le CV
     * est ici le CV fictif des tests de l'assistant, lu par le même extracteur
     * que le vrai.
     */
    public function testACvFragmentInAPromptMessageTurnsTheGuardRed(): void
    {
        $published = $this->publishedBy(self::createClient());
        $mentions = AuthorMentions::of($this->authorName(), (new PopplerPdfTextExtractor(self::FICTIONAL_CV, new ExtractedTextNormalizer(), new NullLogger()))->extract());
        self::assertGreaterThan(0, $mentions->cvFragmentCount(), 'Le CV fictif doit donner des fragments.');
        $published = self::appended($published, ['prompts/get present_for_role en', 'messages', 0, 'content', 'text'], ' Known for: refonte d\'une plateforme de réservation, migration de Symfony.');

        $violations = $mentions->in($published, self::ADMITTED);

        self::assertNotSame([], preg_grep('/^prompts\/get present_for_role en\.messages\[0\]\.content\.text : fragment n°\d+ du CV nominatif$/', $violations));
    }

    private function authorName(): string
    {
        $name = $_ENV['MCP_D8_AUTHOR_NAME'] ?? null;
        self::assertIsString($name, 'MCP_D8_AUTHOR_NAME manque (phpunit.dist.xml, ou le secret en CI).');
        self::assertNotSame('', trim($name), 'MCP_D8_AUTHOR_NAME est vide : le secret de CI est-il posé ?');

        return $name;
    }

    /**
     * Ce que le serveur dit de lui-même, par source.
     *
     * @return array<mixed>
     */
    private function publishedBy(KernelBrowser $client): array
    {
        [$sessionId, $initialize] = $this->openMcpSession($client);

        $published = ['initialize' => $initialize];
        foreach (['tools/list', 'prompts/list', 'resources/list', 'resources/templates/list'] as $method) {
            $published[$method] = $this->resultOf($client, $sessionId, $method);
            // Une page suivante échapperait à la garde.
            self::assertArrayNotHasKey('nextCursor', $published[$method], $method);
        }

        foreach ($published['prompts/list']['prompts'] as $prompt) {
            self::assertIsString($prompt['name']);
            foreach (['fr', 'en'] as $locale) {
                $published['prompts/get '.$prompt['name'].' '.$locale] = $this->resultOf($client, $sessionId, 'prompts/get', [
                    'name' => $prompt['name'],
                    'arguments' => ['role' => self::NEUTRAL_ROLE, 'locale' => $locale],
                ]);
            }
        }

        return $published;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function resultOf(KernelBrowser $client, string $sessionId, string $method, array $params = []): array
    {
        $response = $this->mcpCall($client, $sessionId, $method, $params);
        self::assertIsArray($response['result'] ?? null, $method.' a répondu une erreur.');

        /** @var array<string, mixed> $result */
        $result = $response['result'];

        return $result;
    }

    /**
     * `$tree` où la chaîne au bout de `$keys` reçoit `$suffix` — la mutation
     * que la garde doit voir.
     *
     * @param array<mixed>     $tree
     * @param list<int|string> $keys
     *
     * @return array<mixed>
     */
    private static function appended(array $tree, array $keys, string $suffix): array
    {
        $key = array_shift($keys);
        self::assertNotNull($key);
        self::assertArrayHasKey($key, $tree);

        if ([] === $keys) {
            self::assertIsString($tree[$key]);
            $tree[$key] .= $suffix;

            return $tree;
        }

        self::assertIsArray($tree[$key]);
        $tree[$key] = self::appended($tree[$key], $keys, $suffix);

        return $tree;
    }

    /**
     * La valeur à un chemin pointé (`initialize.serverInfo.name`), la source
     * étant le premier segment.
     *
     * @param array<mixed> $published
     */
    private function at(array $published, string $path): mixed
    {
        $value = $published;
        foreach (explode('.', $path) as $segment) {
            self::assertIsArray($value, $path);
            self::assertArrayHasKey($segment, $value, $path);
            $value = $value[$segment];
        }

        return $value;
    }
}
