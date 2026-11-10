<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Presentation\Tool;

use App\Ai\Assistant\Application\Corpus\CorpusRendererInterface;
use App\Ai\Assistant\Infrastructure\Corpus\CorpusRenderer;
use App\Ai\Assistant\Infrastructure\Pdf\ExtractedTextNormalizer;
use App\Ai\Assistant\Infrastructure\Pdf\PopplerPdfTextExtractor;
use App\Mcp\Infrastructure\Session\BoundedSessionStore;
use App\Mcp\Presentation\Tool\ToolLocale;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Tests\Ai\Assistant\Support\StubCorpusRenderer;
use App\Tests\Ai\Assistant\Support\StubProvider;
use App\Tests\Mcp\Support\HoldsAnMcpConnection;
use App\Tests\Mcp\Support\SpeaksMcp;
use App\Tests\Support\ReadsAllChannelsLog;
use App\Tests\Support\ReadsSecurityAuditLog;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Discovery\SchemaValidator;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * `get_nominative_corpus` (spec 0007, tâches 2 et 9) : **toujours listé**, sa
 * description dit qu'il exige une connexion MCP (ADR 0006 D3).
 *
 * Avec une connexion MCP valide (le jeton du vrai parcours OAuth,
 * {@see HoldsAnMcpConnection}), l'outil rend le corpus nominatif et l'adresse
 * de la page du CV, et chaque lecture laisse une ligne `nominative-corpus-read`
 * au journal de sécurité (ADR 0006 D5). Sans connexion, un appel non marqué
 * reçoit le 401 différé avant le SDK (DeferredAuthorizationListenerTest) ; un
 * appel marqué `openai/` (ChatGPT) atteint l'outil, qui rend l'erreur d'outil
 * portant le défi dans `_meta["mcp/www_authenticate"]` — rien du corpus. Le
 * marqueur ne choisit que la forme du refus, jamais l'accès.
 *
 * Le CV de test est `dummy.pdf`, sans texte : le rendu du corpus, public en
 * test, est remplacé par un rendu branché sur le CV fictif des tests de
 * l'assistant (`.claude/rules/ai.md`), pour qu'une fuite ait de quoi se voir.
 * Le texte du vrai CV n'est jamais lu ici.
 */
final class GetNominativeCorpusToolTest extends WebTestCase
{
    use HoldsAnMcpConnection;
    use ReadsAllChannelsLog;
    use ReadsSecurityAuditLog;
    use SpeaksMcp;

    private const string TOOL = 'get_nominative_corpus';

    /** Ce qu'un appel de ChatGPT porte dans son `_meta` (préfixe `openai/`). */
    private const array OPENAI_MARKER = ['openai/subject' => 'subject-1'];

    /** Le texte de l'erreur d'outil sans connexion, recopié de l'outil : un oracle à part. */
    private const string CONNECTION_REQUIRED = 'An MCP connection is required to read the nominative corpus: connect your account on this site from your MCP client, then call this tool again.';

    /** L'`error_description` du défi `insufficient_scope`, dans l'alphabet de RFC 6749 A.2. */
    private const string CONNECTION_REQUIRED_DESCRIPTION = 'An MCP connection to the site is required to read the nominative corpus';

    private const string FICTIONAL_CV = __DIR__.'/../../../Ai/Assistant/Infrastructure/Pdf/Fixtures/cv-fictif.pdf';

    /** Un témoin en tête des corpus de substitution : sa présence, hors de la réponse, dirait une fuite. */
    private const string CORPUS_SENTINEL = 'CORPUS-SENTINEL-5c2e';

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->setUpOAuthFlow();
    }

    protected function tearDown(): void
    {
        self::forgetMcpSessions(self::getContainer()->get(EntityManagerInterface::class)->getConnection());
        $this->tearDownOAuthFlow();
        parent::tearDown();
    }

    public function testItIsListedAndSaysItRequiresAnMcpConnection(): void
    {
        $sessionId = $this->initializeMcpSession($this->client);

        $tool = $this->listedMcpTool($this->client, $sessionId, self::TOOL);

        self::assertStringContainsString('Requires an MCP connection', $tool['description']);
        self::assertTrue($tool['annotations']['readOnlyHint']);
        self::assertSame('string', $tool['inputSchema']['properties']['locale']['type']);
        // Le contexte de requête que le SDK injecte n'est pas un argument.
        self::assertSame(['locale'], array_keys($tool['inputSchema']['properties']));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function locales(): iterable
    {
        foreach (Locale::values() as $locale) {
            yield $locale => [$locale];
        }
    }

    /**
     * Le corpus, celui que rend le renderer de l'assistant dans la langue
     * demandée, à l'octet ; la page du CV sur le site ; le même JSON dans le
     * bloc texte ; le schéma fermé respecté. Jamais le PDF : ni ressource, ni
     * bloc binaire, un seul bloc texte.
     */
    #[DataProvider('locales')]
    public function testAValidConnectionReadsTheCorpusInEachLanguage(string $locale): void
    {
        $held = $this->holdMcpConnection();
        $renderer = $this->useFictionalCv();
        $sessionId = $this->initializeMcpSession($this->client, self::bearer($held->accessToken));

        $listed = $this->mcpCall($this->client, $sessionId, 'tools/list', server: self::bearer($held->accessToken));
        $result = $this->toolResult($sessionId, ['locale' => $locale], bearer: self::bearer($held->accessToken));

        self::assertFalse($result['isError'] ?? false);
        $expected = [
            'corpus' => $renderer->render(Locale::from($locale)),
            'cvPageUrl' => $this->frontendBaseUrl().'/'.$locale.'/assistant',
        ];
        self::assertSame($expected, $result['structuredContent']);
        self::assertCount(1, $result['content']);
        self::assertSame('text', $result['content'][0]['type']);
        self::assertSame($expected, json_decode($result['content'][0]['text'], true, flags: \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('%PDF', $this->rawMcpAnswer($this->client));

        self::assertIsArray($listed['result']['tools'] ?? null);
        $schema = array_column($listed['result']['tools'], 'outputSchema', 'name')[self::TOOL] ?? null;
        self::assertIsArray($schema);
        /** @var array<string, mixed> $schema */
        self::assertFalse($schema['additionalProperties']);
        self::assertSame(['corpus', 'cvPageUrl'], $schema['required']);
        self::assertSame([], new SchemaValidator()->validateAgainstJsonSchema($result['structuredContent'], $schema));

        // Une lecture réussie ne laisse le corpus dans aucun journal, aucun
        // canal ni niveau (la sonde all_channels_test est au niveau debug) :
        // ni le canal du SDK, qui reçoit la réponse, ni l'audit.
        $this->assertNoLogCarries(...$this->fictionalCvLines());
    }

    /**
     * Une ligne `nominative-corpus-read` par lecture, qui désigne le compte
     * (sujet et auteur : c'est le jeton de sa connexion qui a été
     * authentifié), la connexion, le client par son identifiant public et
     * l'outil ; aucune sur un refus, ni sans connexion ni sur une langue
     * inconnue.
     */
    public function testEachReadIsAudited(): void
    {
        $held = $this->holdMcpConnection();
        $this->useFictionalCv();
        $sessionId = $this->initializeMcpSession($this->client, self::bearer($held->accessToken));

        foreach (Locale::values() as $locale) {
            $this->toolResult($sessionId, ['locale' => $locale], bearer: self::bearer($held->accessToken));

            $line = self::singleSecurityAuditEvent('nominative-corpus-read');
            self::assertSame($held->accountId, $line['userId'] ?? null);
            self::assertSame($held->accountId, $line['actor'] ?? null);
            self::assertSame($held->connectionId, $line['connectionId'] ?? null);
            self::assertSame($held->clientId, $line['clientId'] ?? null);
            self::assertSame(self::TOOL, $line['tool'] ?? null);
            self::assertSame('/api/mcp', $line['path'] ?? null);
        }

        $this->toolResult($sessionId, ['locale' => 'xx'], bearer: self::bearer($held->accessToken));
        self::assertSame([], self::securityAuditEvents('nominative-corpus-read'), 'Une langue inconnue n\'est pas une lecture.');

        $anonymousSession = $this->initializeMcpSession($this->client);
        $this->toolResult($anonymousSession, ['locale' => 'fr'], meta: self::OPENAI_MARKER);
        self::assertSame([], self::securityAuditEvents('nominative-corpus-read'), 'Un refus n\'est pas une lecture.');
    }

    /**
     * Le refus que ChatGPT sait suivre : une erreur d'outil (HTTP 200), le
     * défi `insufficient_scope` complet dans `_meta["mcp/www_authenticate"]`
     * — une liste d'un défi, la forme de la référence de l'Apps SDK —, le
     * texte qui dit quoi faire, et rien du corpus.
     */
    public function testAMarkedAnonymousCallGetsTheChatGptRefusal(): void
    {
        $this->useFictionalCv();
        $sessionId = $this->initializeMcpSession($this->client);

        $result = $this->toolResult($sessionId, ['locale' => 'fr'], meta: self::OPENAI_MARKER);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertFalse($this->client->getResponse()->headers->has('WWW-Authenticate'));
        self::assertTrue($result['isError']);
        self::assertArrayNotHasKey('structuredContent', $result);
        self::assertSame([['type' => 'text', 'text' => self::CONNECTION_REQUIRED]], $result['content']);
        self::assertSame(['mcp/www_authenticate' => [
            'Bearer resource_metadata="'.$this->publicApiBase().'/.well-known/oauth-protected-resource/api/mcp", scope="nominative:read", error="insufficient_scope", error_description="'.self::CONNECTION_REQUIRED_DESCRIPTION.'"',
        ]], $result['_meta'] ?? null);
        $this->assertNothingOfTheCorpusIn($this->rawMcpAnswer($this->client));
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function callers(): iterable
    {
        yield 'sans jeton, non marqué' => [false, false];
        yield 'sans jeton, marqué openai/' => [false, true];
        yield 'connexion valide, non marquée' => [true, false];
        yield 'connexion valide, marquée openai/' => [true, true];
    }

    /**
     * Le marqueur ne décide jamais de l'accès : sans jeton, jamais le corpus
     * (le 401 différé, ou l'erreur d'outil) ; avec une connexion, le corpus,
     * marqué ou non.
     */
    #[DataProvider('callers')]
    public function testTheMarkerDecidesNoAccess(bool $connected, bool $marked): void
    {
        $bearer = $connected ? self::bearer($this->holdMcpConnection()->accessToken) : [];
        $renderer = $this->useFictionalCv();
        $sessionId = $this->initializeMcpSession($this->client, $bearer);

        $this->postMcp($this->client, $sessionId, $this->callMessage(['locale' => 'en'], $marked ? self::OPENAI_MARKER : []), $bearer);

        $response = $this->client->getResponse();
        if (!$connected) {
            $this->assertNothingOfTheCorpusIn((string) $response->getContent());
            if (!$marked) {
                self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());

                return;
            }
        }

        $result = $this->decodeMcpResponse($this->client)['result'] ?? null;
        self::assertIsArray($result);
        if ($connected) {
            self::assertFalse($result['isError'] ?? false);
            self::assertSame($renderer->render(Locale::EN), $result['structuredContent']['corpus'] ?? null);
        } else {
            self::assertTrue($result['isError']);
        }
    }

    public function testAnUnknownLanguageStaysAToolError(): void
    {
        $held = $this->holdMcpConnection();
        $this->useFictionalCv();
        $sessionId = $this->initializeMcpSession($this->client, self::bearer($held->accessToken));

        $result = $this->toolResult($sessionId, ['locale' => 'de'], bearer: self::bearer($held->accessToken));

        self::assertTrue($result['isError']);
        self::assertSame([['type' => 'text', 'text' => ToolLocale::UNKNOWN_LANGUAGE]], $result['content']);
        $this->assertNothingOfTheCorpusIn($this->rawMcpAnswer($this->client));
    }

    /**
     * Une session est liée à la première connexion qui l'utilise (#648,
     * décision de l'auteur du 2026-10-24). En 0.8.1, le SDK mettait la
     * réponse d'un outil en file dans la session, puis vidait toute la file
     * vers n'importe quel `POST` qui portait son identifiant : un appelant
     * anonyme concurrent aurait reçu le corpus de l'appel authentifié. Depuis
     * la 0.9 la réponse ne passe plus par la session
     * ({@see self::testTheAnswerNeverTransitsThroughTheSession()}), et la
     * liaison est gardée en défense en profondeur (#731) : la session porte ce
     * que la connexion a déclaré, et la file des messages que le serveur
     * initierait. Liée, la session n'existe plus pour l'appelant anonyme : un
     * `ping` et le `GET` du flux reçoivent le 404 qui fait se réinitialiser un
     * client, rien du corpus ; son `DELETE` ne la détruit pas — la connexion
     * continue de s'en servir.
     */
    public function testABoundSessionDoesNotExistForAnAnonymousCaller(): void
    {
        $held = $this->holdMcpConnection();
        $this->useFictionalCv();
        $sessionId = $this->initializeMcpSession($this->client, self::bearer($held->accessToken));
        $result = $this->toolResult($sessionId, ['locale' => 'fr'], bearer: self::bearer($held->accessToken));
        self::assertFalse($result['isError'] ?? false, 'Le corpus devait être servi d\'abord.');

        $this->postMcp($this->client, $sessionId, ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'ping']);
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
        $this->assertNothingOfTheCorpusIn((string) $this->client->getResponse()->getContent());

        $this->getMcpStream($sessionId);
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
        $this->assertNothingOfTheCorpusIn((string) $this->client->getResponse()->getContent());

        $this->client->request('DELETE', '/api/mcp', server: [
            'HTTP_MCP_SESSION_ID' => $sessionId,
            'HTTP_MCP_PROTOCOL_VERSION' => self::MCP_PROTOCOL_VERSION,
        ]);
        $this->assertSessionServes($sessionId, self::bearer($held->accessToken));
    }

    /**
     * La liaison désigne une connexion, pas « un appelant authentifié » :
     * reprise par une autre connexion de confiance, la session est inexistante
     * pour elle aussi, et reste celle de la première.
     */
    public function testABoundSessionDoesNotExistForAnotherConnection(): void
    {
        $first = $this->holdMcpConnection();
        $second = $this->holdAnotherMcpConnection();
        self::assertNotSame($first->connectionId, $second->connectionId);
        $this->useFictionalCv();
        $sessionId = $this->initializeMcpSession($this->client, self::bearer($first->accessToken));
        $this->toolResult($sessionId, ['locale' => 'en'], bearer: self::bearer($first->accessToken));

        $this->postMcp($this->client, $sessionId, $this->callMessage(['locale' => 'en']), self::bearer($second->accessToken));
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
        $this->assertNothingOfTheCorpusIn((string) $this->client->getResponse()->getContent());

        $this->assertSessionServes($sessionId, self::bearer($first->accessToken));
    }

    /**
     * Une session ouverte anonymement reste servie en anonyme tant qu'aucune
     * connexion ne l'a utilisée ; la connexion qui l'utilise ensuite y lit le
     * corpus, et la session est désormais la sienne : le `ping` anonyme
     * suivant reçoit le 404.
     */
    public function testASessionOpenedAnonymouslyIsBoundByTheConnectionThatUsesIt(): void
    {
        $held = $this->holdMcpConnection();
        $renderer = $this->useFictionalCv();
        $sessionId = $this->initializeMcpSession($this->client);
        $this->assertSessionServes($sessionId, []);

        $result = $this->toolResult($sessionId, ['locale' => 'fr'], bearer: self::bearer($held->accessToken));
        self::assertSame($renderer->render(Locale::FR), $result['structuredContent']['corpus'] ?? null);

        $this->postMcp($this->client, $sessionId, ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'ping']);
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    /**
     * La ligne `cache_items` d'une session où le corpus a été servi porte la
     * connexion : la liaison est écrite par le décorateur borné dans la même
     * écriture que la session (BoundedSessionStoreTest rejoue chaque écriture
     * de la séquence d'un `tools/call` du SDK).
     */
    public function testTheSessionRowWhereTheCorpusWasServedIsBound(): void
    {
        $held = $this->holdMcpConnection();
        $this->useFictionalCv();
        $sessionId = $this->initializeMcpSession($this->client);

        $this->toolResult($sessionId, ['locale' => 'fr'], bearer: self::bearer($held->accessToken));

        self::assertTrue((bool) $this->mcpRows()->fetchOne(
            "SELECT position(convert_to(:binding, 'UTF8') IN item_data) > 0 FROM cache_items WHERE item_id LIKE :key",
            ['binding' => 'mcp-connection:'.$held->connectionId."\n", 'key' => '%mcp-portfolio-'.$sessionId.'%'],
        ), 'La session doit être liée à la connexion.');
    }

    /**
     * La prémisse que pose la 0.9 du SDK : la réponse d'un `POST` reste au
     * transport, qui la rend dans la réponse HTTP ; la file de sortie de la
     * session ne porte plus que les messages dont le serveur a l'initiative,
     * et ce serveur n'en émet aucun (ni notification, ni requête au client).
     * En 0.8.1 c'était l'inverse : le SDK mettait la réponse en file dans la
     * session au `save()`, puis la vidait vers le `POST` — le corpus nominatif
     * passait donc par `cache_items`, et ce test y rougissait.
     *
     * Pourquoi elle compte : tant que la réponse passait par la session,
     * l'outil la bornait et refusait un corpus trop grand — borne et refus
     * retirés par #731 —, et la liaison d'une session à sa connexion y
     * trouvait son motif d'origine, gardée depuis en défense en profondeur
     * (BoundedSessionStore). L'un et l'autre reposent sur cette prémisse : elle
     * est tenue par un test, pas par une lecture du SDK.
     *
     * Ce qui est lu : les écritures de `cache_items` que Doctrine journalise
     * (canal `doctrine`, `debug`), paramètres compris, pendant l'appel de
     * l'outil. La sonde ne garde que la dernière requête, même sans
     * redémarrage du noyau ({@see self::useRenderer()}) : elle est vidée à
     * chaque requête (#769), si bien qu'après l'appel elle ne porte que ses
     * écritures. Les lectures de la même ligne portent l'identifiant de la
     * session sans rien écrire : seules les écritures (`INSERT`, `UPDATE`)
     * sont retenues.
     *
     * Deux assertions, de portées différentes. « Pas de corpus » vaut pour
     * **toute** écriture de `cache_items` de la requête, quelle que soit sa
     * clé : une copie de la réponse rangée ailleurs que sous la clé de la
     * session (une autre ligne, un autre pool en base) n'échappe pas. Le
     * témoin positif — les paramètres journalisés portent bien la valeur
     * écrite, liaison comprise — ne vaut que pour les écritures de la
     * session, reconnues à sa clé ou à sa liaison.
     */
    public function testTheAnswerNeverTransitsThroughTheSession(): void
    {
        $held = $this->holdMcpConnection();
        $this->useRenderer(new StubCorpusRenderer(self::CORPUS_SENTINEL));
        $sessionId = $this->initializeMcpSession($this->client, self::bearer($held->accessToken));

        $this->toolResult($sessionId, ['locale' => 'fr'], bearer: self::bearer($held->accessToken));

        $binding = 'mcp-connection:'.$held->connectionId;
        $sessionWrites = [];
        foreach (self::allChannelsLogRecordsOf('doctrine') as $record) {
            $sql = $record->context['sql'] ?? null;
            if (!str_starts_with($record->message, 'Executing ') || !\is_string($sql) || !str_contains($sql, 'cache_items')) {
                continue;
            }
            if (1 !== preg_match('/^\s*(?:INSERT|UPDATE)\b/i', $sql)) {
                continue;
            }

            $params = print_r($record->context['params'] ?? null, true);
            // Toute écriture de `cache_items`, sous quelque clé que ce soit.
            self::assertStringNotContainsString(self::CORPUS_SENTINEL, $params, 'La réponse a transité par `cache_items`.');
            if (str_contains($params, 'mcp-portfolio-'.$sessionId) || str_contains($params, $binding."\n")) {
                $sessionWrites[] = $params;
            }
        }

        self::assertNotSame([], $sessionWrites, 'Aucune écriture de session relevée : le test ne verrait rien.');
        foreach ($sessionWrites as $params) {
            // Témoin positif : les paramètres journalisés portent bien la valeur écrite.
            self::assertStringContainsString($binding, $params);
        }
        self::assertStringContainsString(self::CORPUS_SENTINEL, $this->rawMcpAnswer($this->client));
    }

    /**
     * Un corpus de n'importe quelle taille est servi en entier (#731, effet
     * métier accepté par l'auteur le 2026-11-10) : la réponse ne passe plus
     * par la session, donc rien ne la borne dans l'outil. Jusqu'à #731,
     * l'outil refusait une réponse qui, mise en file dans la session, en
     * aurait dépassé la moitié de la borne : une erreur d'outil renvoyant à
     * la page du CV, sans ligne d'audit.
     *
     * Le corpus pèse ici, à lui seul, plus que la borne de session
     * ({@see BoundedSessionStore::MAX_BYTES}), et la réponse le porte deux
     * fois : s'il passait encore par la session, celle-ci serait détruite.
     * Il arrive entier, sa lecture est journalisée, aucun `warning` ne part
     * (ni la borne de session ni un refus de l'outil), le corpus n'est dans
     * aucun journal, et la session sert la requête suivante.
     */
    public function testACorpusLargerThanTheSessionBoundIsServedInFull(): void
    {
        $held = $this->holdMcpConnection();
        $corpus = self::CORPUS_SENTINEL.str_repeat('x', BoundedSessionStore::MAX_BYTES);
        $this->useRenderer(new StubCorpusRenderer($corpus));
        $sessionId = $this->initializeMcpSession($this->client, self::bearer($held->accessToken));

        $result = $this->toolResult($sessionId, ['locale' => 'fr'], bearer: self::bearer($held->accessToken));

        self::assertFalse($result['isError'] ?? false, 'Un corpus très gros est servi, jamais refusé pour sa taille.');
        self::assertSame($corpus, $result['structuredContent']['corpus'] ?? null);
        self::assertSame($this->frontendBaseUrl().'/fr/assistant', $result['structuredContent']['cvPageUrl'] ?? null);
        self::assertSame($held->connectionId, self::singleSecurityAuditEvent('nominative-corpus-read')['connectionId'] ?? null);

        $warnings = array_map(
            static fn (LogRecord $record): string => $record->channel.' : '.$record->message,
            array_values(array_filter(self::allChannelsLogRecords(), static fn (LogRecord $record): bool => $record->level->isHigherThan(Level::Notice))),
        );
        self::assertSame([], $warnings, 'Ni la borne de session ni l\'outil ne refusent rien.');
        $this->assertNoLogCarries(self::CORPUS_SENTINEL);

        $this->assertSessionServes($sessionId, self::bearer($held->accessToken));
    }

    /**
     * Remplace le rendu du corpus par un rendu branché sur le CV fictif, et
     * le rend : l'étalon auquel la sortie se compare.
     */
    private function useFictionalCv(): CorpusRendererInterface
    {
        return $this->useRenderer(new CorpusRenderer(
            new PopplerPdfTextExtractor(self::FICTIONAL_CV, new ExtractedTextNormalizer(), new NullLogger()),
            new StubProvider([]),
            new StubProvider([]),
        ));
    }

    /**
     * Le renderer, public en test (`services.yaml`), remplacé dans le conteneur
     * qui servira les requêtes suivantes : sans `disableReboot()`, le kernel
     * serait reconstruit à la prochaine requête et le remplacement perdu.
     */
    private function useRenderer(CorpusRendererInterface $renderer): CorpusRendererInterface
    {
        $this->client->disableReboot();
        $this->client->getContainer()->set(CorpusRenderer::class, $renderer);

        return $renderer;
    }

    /**
     * Aucune ligne du corpus fictif (hors balises et intertitres, qui ne
     * disent rien du CV) dans `$body`, en clair ni échappée en JSON.
     */
    private function assertNothingOfTheCorpusIn(string $body): void
    {
        foreach ($this->fictionalCvLines() as $line) {
            self::assertStringNotContainsString($line, $body);
            self::assertStringNotContainsString($this->jsonEscaped($line), $body);
        }
    }

    /**
     * Aucun enregistrement de la sonde (tous canaux, tous niveaux) ne porte
     * l'un des fragments, en clair ni échappé en JSON (`\u00e9`, `\ud83d`,
     * `\"`) : le message, et le contexte encodé des deux façons dont un
     * gestionnaire peut l'écrire.
     *
     * Seules les requêtes SQL de `doctrine` (debug) sont écartées, comme dans
     * AccountIdentifierReachesNoLogTest : elles portent la valeur écrite dans
     * `cache_items`. En 0.8.1 du SDK cette valeur portait la file de la
     * session, donc le corpus ; depuis la 0.9 la réponse n'y passe plus, ce
     * que {@see self::testTheAnswerNeverTransitsThroughTheSession()} lit sur
     * ces écritures mêmes — par `print_r` : la valeur est binaire (le MAC du
     * cache), et l'encodage JSON fait ici ne la verrait pas (mesuré le
     * 2026-11-10 ; l'exclusion retirée, la classe reste verte, sans rien
     * prouver de plus pour cette raison). L'exclusion est gardée : ce garde
     * lit les journaux qui sortent, et ces lignes-là ne sortent pas. Le
     * gestionnaire
     * `main` de prod **ne les exclut pas** (`monolog.yaml` n'écarte pas le
     * canal `doctrine`), et la préprod tourne à `LOG_LEVEL=debug` : ce qui les
     * coupe est l'absence de `kernel.debug`, seule condition sous laquelle
     * DoctrineBundle journalise le SQL (`doctrine.dbal.logging`, que
     * `doctrine.yaml` laisse à sa valeur par défaut, `%kernel.debug%`). Elles
     * ne sortent donc ni en préprod ni en prod **tant qu'`APP_DEBUG` y vaut
     * 0** (décision de l'auteur, relecture de #648 : la phrase corrigée, pas
     * la configuration).
     */
    private function assertNoLogCarries(string ...$fragments): void
    {
        foreach (self::allChannelsLogRecords() as $record) {
            if ('doctrine' === $record->channel && Level::Debug === $record->level) {
                continue;
            }

            $written = $record->message
                .json_encode($record->context, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR)
                .json_encode($record->context, \JSON_PARTIAL_OUTPUT_ON_ERROR);
            foreach ($fragments as $fragment) {
                self::assertStringNotContainsString($fragment, $written, $record->channel.' : '.$record->message);
                self::assertStringNotContainsString($this->jsonEscaped($fragment), $written, $record->channel.' : '.$record->message);
            }
        }
    }

    /**
     * Les lignes du CV fictif assez longues pour ne rien désigner d'autre
     * (douze caractères au moins) : ce qu'une fuite du corpus laisserait voir.
     *
     * @return non-empty-list<string>
     */
    private function fictionalCvLines(): array
    {
        $cvText = new PopplerPdfTextExtractor(self::FICTIONAL_CV, new ExtractedTextNormalizer(), new NullLogger())->extract();
        self::assertIsString($cvText, 'Le CV fictif doit donner un texte.');

        $lines = preg_split('/\R/u', $cvText);
        self::assertIsArray($lines);
        $long = array_values(array_filter(array_map(trim(...), $lines), static fn (string $line): bool => mb_strlen($line) >= 12));
        self::assertNotSame([], $long, 'Aucune ligne du CV fictif à chercher.');

        return $long;
    }

    /** `$text` tel que l'écrit un `json_encode()` sans drapeau, guillemets d'encadrement ôtés. */
    private function jsonEscaped(string $text): string
    {
        return trim(json_encode($text, \JSON_THROW_ON_ERROR), '"');
    }

    /**
     * @param array<string, string> $arguments
     * @param array<string, string> $meta
     *
     * @return array<string, mixed>
     */
    private function callMessage(array $arguments, array $meta = []): array
    {
        $params = ['name' => self::TOOL, 'arguments' => $arguments];
        if ([] !== $meta) {
            $params['_meta'] = $meta;
        }

        return ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => $params];
    }

    /**
     * Le `result` d'un appel de l'outil, erreur d'outil comprise.
     *
     * @param array<string, string> $arguments
     * @param array<string, string> $meta
     * @param array<string, string> $bearer
     *
     * @return array<string, mixed>
     */
    private function toolResult(string $sessionId, array $arguments, array $meta = [], array $bearer = []): array
    {
        $this->postMcp($this->client, $sessionId, $this->callMessage($arguments, $meta), $bearer);

        $result = $this->decodeMcpResponse($this->client)['result'] ?? null;
        self::assertIsArray($result, 'tools/call n\'a pas rendu de `result` : '.substr((string) $this->client->getResponse()->getContent(), 0, 300));

        /** @var array<string, mixed> $result */
        return $result;
    }

    /**
     * Un `ping` porté par `$bearer` (anonyme si vide) est servi dans la
     * session : elle existe pour cet appelant.
     *
     * @param array<string, string> $bearer
     */
    private function assertSessionServes(string $sessionId, array $bearer): void
    {
        $this->postMcp($this->client, $sessionId, ['jsonrpc' => '2.0', 'id' => 9, 'method' => 'ping'], $bearer);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame([], $this->decodeMcpResponse($this->client)['result'] ?? null);
    }

    /** Le `GET` du flux, anonyme, sur la session. */
    private function getMcpStream(string $sessionId): void
    {
        $this->client->request('GET', '/api/mcp', server: [
            'HTTP_ACCEPT' => 'text/event-stream',
            'HTTP_MCP_SESSION_ID' => $sessionId,
            'HTTP_MCP_PROTOCOL_VERSION' => self::MCP_PROTOCOL_VERSION,
        ]);
    }

    private function frontendBaseUrl(): string
    {
        return rtrim(self::getContainer()->getParameter('app.frontend_base_url'), '/');
    }
}
