<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Ai\Assistant\Application\Corpus\PdfTextExtractorInterface;
use App\Ai\Assistant\Infrastructure\Pdf\ExtractedTextNormalizer;
use App\Ai\Assistant\Infrastructure\Pdf\PopplerPdfTextExtractor;
use App\Mcp\Infrastructure\Http\InboundMessageRefusal;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Tests\Mcp\Support\AuthorMention;
use App\Tests\Mcp\Support\AuthorMentions;
use App\Tests\Mcp\Support\MentionKind;
use App\Tests\Mcp\Support\SpeaksMcp;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use stdClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Garde D8 (ADR 0006, spec 0007 tâche 3) : rien de ce que le serveur MCP dit
 * de lui-même ne nomme l'auteur du site.
 *
 * Ce que le serveur dit de lui-même est lu par n'importe quel client, donc au
 * palier anonyme, et le test le lit **par HTTP, sur le serveur réel** — un
 * outil, un argument ou un prompt ajouté demain y passe sans qu'on l'inscrive
 * ici :
 *
 *  - la réponse à `initialize` (`serverInfo`, `instructions`), `tools/list`,
 *    `prompts/list`, `resources/list`, `resources/templates/list` ;
 *  - le message de chaque prompt, dans chaque langue qu'il déclare, et ses
 *    réponses aux saisies invalides (trop longue, vide, langue inconnue,
 *    argument requis absent) ;
 *  - `completion/complete` sur chaque argument de prompt ;
 *  - l'erreur de chaque outil à une langue inconnue, et la sortie des outils
 *    **sans route** (`McpExposureSymmetryTest::TOOLS_WITHOUT_ROUTE`), que le
 *    serveur écrit lui-même. La sortie d'un outil adossé, elle, est le contenu
 *    de sa route, public par décision d'auteur (D1), et n'est pas lue.
 *
 * Le nom vient de la configuration de test (`MCP_D8_AUTHOR_NAME`,
 * `phpunit.dist.xml`), jamais du test : fictif en local, le vrai en CI par un
 * secret, dans une étape qui ne lance que ce groupe (`d8`). Le CV est celui que
 * sert `GET /api/cv` (`app.cv_file_path`) : sans texte en CI (`dummy.pdf`), le
 * vrai sur un poste qui le porte. Ni l'un ni l'autre n'est jamais cité dans un
 * message d'échec ({@see AuthorMentions}) ; en CI, dont les journaux sont
 * publics, l'échec ne donne même que le nombre de mentions.
 */
#[Group('d8')]
final class AuthorNeverNamedTest extends WebTestCase
{
    use SpeaksMcp;

    /** Une valeur neutre pour chaque argument requis d'un prompt : le test lit ce que le serveur y ajoute. */
    private const string NEUTRAL_ARGUMENT = 'Backend developer';

    /** Une langue qu'aucun outil ni prompt ne connaît. */
    private const string UNKNOWN_LOCALE = 'xx';

    private const string FICTIONAL_CV = __DIR__.'/../Ai/Assistant/Infrastructure/Pdf/Fixtures/cv-fictif.pdf';

    private const string TEXTLESS_CV = __DIR__.'/../Portfolio/Cv/Fixtures/dummy.pdf';

    /**
     * Ce que le serveur publie, lu une fois pour toute la classe : chaque test
     * en mute une copie. Lire à chaque test coûtait une quarantaine de requêtes.
     *
     * @var array<mixed>|null
     */
    private static ?array $published = null;

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
        $published = $this->published();
        $mentions = AuthorMentions::of($this->authorName(), $this->nominativeCvText());

        $found = $mentions->in($published, $this->admitted());

        if ('true' === getenv('GITHUB_ACTIONS')) {
            // Les journaux de CI sont publics : un chemin et un numéro de
            // terme diraient quel mot publié est le nom de l'auteur.
            self::assertCount(0, $found, \sprintf('%d mention(s) de l\'auteur ; détail masqué en CI, relancer le groupe d8 en local avec MCP_D8_AUTHOR_NAME.', \count($found)));
        } else {
            self::assertSame([], array_map(strval(...), $found));
        }
    }

    /**
     * Chaque surface promise est bien lue : sans elle, la garde passerait au
     * vert sur ce qu'elle ne voit pas.
     */
    public function testEverySurfaceTheServerWritesIsRead(): void
    {
        $published = $this->published();

        foreach (['initialize', 'tools/list', 'prompts/list', 'resources/list', 'resources/templates/list'] as $source) {
            self::assertArrayHasKey($source, $published);
        }
        foreach (['fr', 'en', 'trop long', 'vide', 'langue inconnue', 'role absent'] as $case) {
            self::assertArrayHasKey('prompts/get present_for_role '.$case, $published);
        }
        self::assertArrayHasKey('error', $published['prompts/get present_for_role trop long']);
        self::assertArrayHasKey('completion/complete present_for_role role', $published);
        self::assertArrayHasKey('tools/call get_about langue inconnue', $published);
        self::assertArrayHasKey('tools/call get_contact_info fr', $published);
        self::assertArrayHasKey('tools/call get_nominative_corpus en', $published);
        foreach (['lot refusé', 'réponse du client refusée', 'initialize refusé'] as $refusal) {
            self::assertArrayHasKey('error', $published[$refusal], $refusal);
        }
    }

    /**
     * Une exception que le serveur ne publie plus telle quelle est une entrée
     * morte : elle admettrait demain une valeur que personne n'a décidée. Le
     * diff d'un `assertSame` afficherait la valeur publiée : un booléen, pas
     * de diff.
     */
    public function testEveryAdmittedExceptionIsPublishedExactlyAsTheAdrWritesIt(): void
    {
        $published = $this->published();

        foreach ($this->admitted() as $path => $value) {
            self::assertTrue($value === $this->valueAt($published, $path), $path.' : la valeur publiée diffère de celle que l\'ADR 0006 admet.');
        }
    }

    /**
     * Critère de la tâche : la garde passe au rouge quand une description
     * d'outil reçoit le nom de test — entier, son dernier mot seul en
     * capitales, accolé et suivi de chiffres, en pleine chasse, coupé d'un
     * caractère invisible.
     */
    public function testTheTestNameInAToolDescriptionTurnsTheGuardRed(): void
    {
        $name = $this->authorName();
        $words = $this->words($name);
        $lastWord = (string) array_last($words);
        $variants = [
            ' Written by '.$name.'.',
            ' Ask '.mb_strtoupper($lastWord).'.',
            ' Mail '.implode('', $words).'22@example.test.',
            ' By '.$this->fullWidth(mb_strtoupper($lastWord)).'.',
            ' By '.mb_substr($lastWord, 0, 2)."\u{00AD}".mb_substr($lastWord, 2).'.',
        ];

        foreach ($variants as $index => $variant) {
            $published = self::appended($this->published(), ['tools/list', 'tools', 0, 'description'], $variant);

            $found = AuthorMentions::of($name, null)->in($published, $this->admitted());

            self::assertNotSame([], $this->at($found, 'tools/list.tools[0].description'), 'variante n°'.$index);
        }
    }

    /**
     * Les initiales, comme mot en capitales ou en tête d'un identifiant
     * (`xy-…`) : la lecture que l'ADR 0006 prête à `cp-ghostotof`. L'exception
     * n'admet que sa valeur et à son chemin.
     */
    public function testInitialsAreAdmittedOnlyWhereTheAdrPutsThem(): void
    {
        $initials = implode('', array_map(static fn (string $word): string => mb_strtolower(mb_substr($word, 0, 1)), $this->words($this->authorName())));
        $identifier = $initials.'-ghost';
        $published = self::appended($this->published(), ['initialize', 'instructions'], ' See '.$identifier.' and '.mb_strtoupper($initials).'.');
        $published['initialize']['serverInfo']['name'] = $identifier;

        $found = AuthorMentions::of($this->authorName(), null)->in($published, ['initialize.serverInfo.name' => $identifier]);

        self::assertSame([], $this->at($found, 'initialize.serverInfo.name'));
        self::assertSame(
            [MentionKind::Initials, MentionKind::Initials],
            array_map(static fn (AuthorMention $mention): MentionKind => $mention->kind, $this->at($found, 'initialize.instructions')),
        );
    }

    /** L'exception n'admet que sa valeur : un autre nom à son chemin est lu comme le reste. */
    public function testTheNameAtAnAdmittedPathIsStillRefused(): void
    {
        $published = $this->published();
        $published['initialize']['serverInfo']['name'] = $this->authorName();

        $found = AuthorMentions::of($this->authorName(), null)->in($published, $this->admitted());

        self::assertNotSame([], $this->at($found, 'initialize.serverInfo.name'));
    }

    /** Une surface d'erreur est lue comme le reste : le nom dans un refus du prompt fait échouer la garde. */
    public function testTheNameInAnErrorMessageTurnsTheGuardRed(): void
    {
        $published = self::appended($this->published(), ['prompts/get present_for_role trop long', 'error', 'message'], ' Ask '.$this->authorName().'.');

        $found = AuthorMentions::of($this->authorName(), null)->in($published, $this->admitted());

        self::assertNotSame([], $this->at($found, 'prompts/get present_for_role trop long.error.message'));
    }

    /**
     * Un fragment du CV dans un message de prompt fait échouer la garde. Le CV
     * est ici le CV fictif des tests de l'assistant, lu par le même extracteur
     * que le vrai, et le fragment en est tiré, pas recopié.
     */
    public function testACvFragmentInAPromptMessageTurnsTheGuardRed(): void
    {
        $cvText = (new PopplerPdfTextExtractor(self::FICTIONAL_CV, new ExtractedTextNormalizer(), new NullLogger()))->extract();
        self::assertIsString($cvText, 'Le CV fictif doit donner un texte.');
        $fragment = implode(' ', \array_slice($this->words($cvText), 10, 6));
        $published = self::appended($this->published(), ['prompts/get present_for_role en', 'result', 'messages', 0, 'content', 'text'], ' Known for: '.$fragment.'.');

        $found = AuthorMentions::of($this->authorName(), $cvText)->in($published, $this->admitted());

        $kinds = array_map(static fn (AuthorMention $mention): MentionKind => $mention->kind, $this->at($found, 'prompts/get present_for_role en.result.messages[0].content.text'));
        self::assertContains(MentionKind::CvFragment, $kinds);
    }

    /**
     * Les exceptions acceptées à D8, **recopiées de la liste fermée de l'ADR
     * 0006** : chacune décidée par l'auteur, admise à son chemin et pour sa
     * valeur exactes, nulle part ailleurs. Une exception nouvelle s'ajoute
     * d'abord à l'ADR, puis ici.
     *
     * @return array<string, string> chemin => valeur admise
     */
    private function admitted(): array
    {
        $admitted = [
            // 2026-10-19, relecture de la tâche 1 (#640) : le nom reprend celui
            // du domaine, déjà public. Pour ce serveur uniquement.
            'initialize.serverInfo.name' => 'cp-ghostotof',
        ];

        // 2026-10-20, relecture de la tâche 3 (#642) : l'adresse de la page
        // de contact, sur le domaine du site, déjà public — dans les deux
        // formes que rend l'outil.
        $baseUrl = self::getContainer()->getParameter('app.frontend_base_url');
        foreach (Locale::values() as $locale) {
            $payload = ['contactPageUrl' => $baseUrl.'/'.$locale.'/contact'];
            $source = 'tools/call get_contact_info '.$locale;
            $admitted[$source.'.structuredContent.contactPageUrl'] = $payload['contactPageUrl'];
            $admitted[$source.'.content[0].text'] = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
        }

        return $admitted;
    }

    private function authorName(): string
    {
        $name = $_ENV['MCP_D8_AUTHOR_NAME'] ?? null;
        self::assertIsString($name, 'MCP_D8_AUTHOR_NAME manque (phpunit.dist.xml, ou le secret en CI).');
        self::assertNotSame('', trim($name), 'MCP_D8_AUTHOR_NAME est vide : le secret de CI est-il posé (secrets Actions, et secrets Dependabot pour un push de Dependabot) ?');

        return $name;
    }

    /**
     * Le texte du CV que sert `GET /api/cv`. Hors du CV de test sans texte,
     * l'extraction doit en rendre un : `extract()` rend `null` pour toute
     * panne (binaire absent, délai, texte trop long), et la garde aurait alors
     * cherché zéro fragment sans rien dire.
     */
    private function nominativeCvText(): ?string
    {
        $cvText = self::getContainer()->get(PdfTextExtractorInterface::class)->extract();
        $path = self::getContainer()->getParameter('app.cv_file_path');

        if (is_file($path) && realpath($path) !== realpath(self::TEXTLESS_CV)) {
            self::assertIsString($cvText, 'Le CV nominatif existe mais son texte n\'a pas pu être extrait : la garde ne chercherait aucun fragment.');
        }

        return $cvText;
    }

    /**
     * Ce que le serveur dit de lui-même, par source.
     *
     * @return array<mixed>
     */
    private function published(): array
    {
        return self::$published ??= $this->read(self::createClient());
    }

    /**
     * @return array<mixed>
     */
    private function read(KernelBrowser $client): array
    {
        $session = $this->openMcpSession($client);
        $published = ['initialize' => $session->initializeResult];

        foreach (['tools/list', 'prompts/list', 'resources/list', 'resources/templates/list'] as $method) {
            $answer = $this->answer($client, $session->id, $method);
            self::assertIsArray($answer['result'] ?? null, $method.' a répondu une erreur.');
            // Une page suivante échapperait à la garde.
            self::assertArrayNotHasKey('nextCursor', $answer['result'], $method);
            $published[$method] = $answer['result'];
        }

        foreach ($published['prompts/list']['prompts'] as $prompt) {
            self::assertIsArray($prompt);
            $published += $this->promptAnswers($client, $session->id, $prompt);
        }
        foreach ($published['tools/list']['tools'] as $tool) {
            self::assertIsArray($tool);
            $published += $this->toolAnswers($client, $session->id, $tool);
        }

        // Les refus écrits par le serveur avant le SDK (#644,
        // InboundMessageRefusalListener) : un lot, une réponse du client, un
        // `initialize` qui déclare trop.
        $this->postMcp($client, $session->id, [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping'],
        ]);
        $published['lot refusé'] = $this->decodeMcpResponse($client);
        $this->postMcp($client, $session->id, ['jsonrpc' => '2.0', 'id' => 9, 'result' => new stdClass()]);
        $published['réponse du client refusée'] = $this->decodeMcpResponse($client);
        $this->postMcpInitialize($client, ['name' => str_repeat('n', InboundMessageRefusal::MAX_DECLARATION_BYTES), 'version' => '1']);
        $published['initialize refusé'] = $this->decodeMcpResponse($client);

        return $published;
    }

    /**
     * Le message d'un prompt dans chaque langue qu'il déclare, ses refus, et
     * la complétion de chacun de ses arguments — tous construits depuis ce que
     * `prompts/list` en dit, pas depuis ce prompt-ci.
     *
     * @param array<mixed> $prompt
     *
     * @return array<string, array<mixed>>
     */
    private function promptAnswers(KernelBrowser $client, string $sessionId, array $prompt): array
    {
        self::assertIsString($prompt['name']);
        self::assertIsArray($prompt['arguments']);
        $name = $prompt['name'];
        $declared = array_column($prompt['arguments'], 'name');
        $takesLocale = \in_array('locale', $declared, true);
        $valid = [];
        foreach ($prompt['arguments'] as $argument) {
            self::assertIsArray($argument);
            if (true === ($argument['required'] ?? false)) {
                self::assertIsString($argument['name']);
                $valid[$argument['name']] = self::NEUTRAL_ARGUMENT;
            }
        }

        $cases = [];
        foreach ($takesLocale ? Locale::values() : ['sans langue'] as $locale) {
            $cases[$locale] = $takesLocale ? $valid + ['locale' => $locale] : $valid;
        }
        if ($takesLocale) {
            $cases['langue inconnue'] = $valid + ['locale' => self::UNKNOWN_LOCALE];
        }
        $first = array_key_first($valid);
        if (null !== $first) {
            $cases['trop long'] = [$first => str_repeat('x', 10_000)] + $valid;
            $cases['vide'] = [$first => ''] + $valid;
            $cases[$first.' absent'] = array_diff_key($valid, [$first => true]);
        }

        $answers = [];
        foreach ($cases as $case => $arguments) {
            $answers['prompts/get '.$name.' '.$case] = $this->answer($client, $sessionId, 'prompts/get', ['name' => $name, 'arguments' => [] === $arguments ? new stdClass() : $arguments]);
        }
        foreach ($declared as $argument) {
            self::assertIsString($argument);
            $answers['completion/complete '.$name.' '.$argument] = $this->answer($client, $sessionId, 'completion/complete', [
                'ref' => ['type' => 'ref/prompt', 'name' => $name],
                'argument' => ['name' => $argument, 'value' => ''],
            ]);
        }

        return $answers;
    }

    /**
     * L'erreur d'un outil à une langue inconnue, et, pour un outil sans route,
     * sa sortie dans chaque langue.
     *
     * @param array<mixed> $tool
     *
     * @return array<string, array<mixed>>
     */
    private function toolAnswers(KernelBrowser $client, string $sessionId, array $tool): array
    {
        self::assertIsString($tool['name']);
        $name = $tool['name'];
        $takesLocale = isset($tool['inputSchema']['properties']['locale']);

        $answers = [];
        if ($takesLocale) {
            $answers['tools/call '.$name.' langue inconnue'] = $this->toolCall($client, $sessionId, $name, ['locale' => self::UNKNOWN_LOCALE]);
        }
        if (\array_key_exists($name, McpExposureSymmetryTest::TOOLS_WITHOUT_ROUTE)) {
            foreach ($takesLocale ? Locale::values() : ['sans langue'] as $locale) {
                $answers['tools/call '.$name.' '.$locale] = $this->toolCall($client, $sessionId, $name, $takesLocale ? ['locale' => $locale] : []);
            }
        }

        return $answers;
    }

    /**
     * Le `result` d'un appel d'outil, erreur d'outil comprise.
     *
     * @param array<string, string> $arguments
     *
     * @return array<mixed>
     */
    private function toolCall(KernelBrowser $client, string $sessionId, string $name, array $arguments): array
    {
        $answer = $this->answer($client, $sessionId, 'tools/call', ['name' => $name, 'arguments' => [] === $arguments ? new stdClass() : $arguments]);
        self::assertIsArray($answer['result'] ?? null, 'tools/call « '.$name.' » a répondu une erreur de protocole.');

        return $answer['result'];
    }

    /**
     * La réponse entière, `result` ou `error` : un refus est publié lui aussi.
     *
     * @param array<string, mixed> $params
     *
     * @return array<mixed>
     */
    private function answer(KernelBrowser $client, string $sessionId, string $method, array $params = []): array
    {
        $response = $this->mcpCall($client, $sessionId, $method, $params);
        unset($response['jsonrpc'], $response['id']);

        return $response;
    }

    /**
     * Les mentions trouvées à un chemin exact.
     *
     * @param list<AuthorMention> $found
     *
     * @return list<AuthorMention>
     */
    private function at(array $found, string $path): array
    {
        return array_values(array_filter($found, static fn (AuthorMention $mention): bool => $path === $mention->path));
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
     * La valeur à un chemin écrit comme {@see AuthorMentions} l'écrit : la
     * source (qui ne contient jamais de point), puis des clés séparées par des
     * points et des indices entre crochets.
     *
     * @param array<mixed> $published
     */
    private function valueAt(array $published, string $path): mixed
    {
        preg_match_all('/\[(\d+)\]|\.?([^.\[]+)/', $path, $matches, \PREG_SET_ORDER | \PREG_UNMATCHED_AS_NULL);

        $value = $published;
        foreach ($matches as $match) {
            $segment = null !== $match[1] ? (int) $match[1] : (string) $match[2];
            self::assertIsArray($value, $path);
            self::assertArrayHasKey($segment, $value, $path);
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function words(string $text): array
    {
        $words = preg_split('/\s+/u', trim($text), -1, \PREG_SPLIT_NO_EMPTY);
        self::assertIsArray($words);

        return $words;
    }

    /** Les lettres ASCII d'un mot en pleine chasse (U+FF21 et suivantes) : la forme que NFKC replie. */
    private function fullWidth(string $word): string
    {
        return (string) preg_replace_callback('/[A-Za-z]/', static fn (array $letter): string => mb_chr(mb_ord($letter[0]) + 0xFEE0), $word);
    }
}
