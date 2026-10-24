<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Presentation\Tool;

use App\Ai\Assistant\Application\Corpus\CorpusRendererInterface;
use App\Ai\Assistant\Infrastructure\Corpus\CorpusRenderer;
use App\Ai\Assistant\Infrastructure\Pdf\ExtractedTextNormalizer;
use App\Ai\Assistant\Infrastructure\Pdf\PopplerPdfTextExtractor;
use App\Mcp\Infrastructure\Session\BoundedSessionStore;
use App\Mcp\Presentation\Tool\GetNominativeCorpusTool;
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

    /**
     * Le plus large des caractères UTF-8 (U+1F600, quatre octets) : le JSON
     * sans drapeau du SDK l'écrit en paire de substitution, douze octets.
     */
    private const string WIDEST_CHARACTER = "\u{1F600}";

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
     * Une session n'est pas liée à la connexion qui l'a ouverte (#648, relecture
     * de la tâche 3) : un appelant anonyme qui rejoue l'identifiant d'une
     * session où le corpus a été servi n'en obtient rien, ni par le `GET` du
     * flux ni par un `POST` — la réponse mise en file a été vidée par la
     * requête qui l'a rendue.
     */
    public function testAnAnonymousReuseOfTheSessionGetsNothingOfTheCorpus(): void
    {
        $held = $this->holdMcpConnection();
        $this->useFictionalCv();
        $sessionId = $this->initializeMcpSession($this->client, self::bearer($held->accessToken));
        $result = $this->toolResult($sessionId, ['locale' => 'fr'], bearer: self::bearer($held->accessToken));
        self::assertFalse($result['isError'] ?? false, 'Le corpus devait être servi d\'abord.');

        $this->client->request('GET', '/api/mcp', server: [
            'HTTP_ACCEPT' => 'text/event-stream',
            'HTTP_MCP_SESSION_ID' => $sessionId,
            'HTTP_MCP_PROTOCOL_VERSION' => self::MCP_PROTOCOL_VERSION,
        ]);
        $this->assertNothingOfTheCorpusIn((string) $this->client->getResponse()->getContent());

        $this->postMcp($this->client, $sessionId, ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'ping']);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $this->assertNothingOfTheCorpusIn((string) $this->client->getResponse()->getContent());
    }

    /**
     * La relation entre les deux bornes (décision de l'auteur du 2026-10-24) :
     * un payload juste sous `PAYLOAD_MAX_BYTES`, fait du pire échappement
     * (des caractères de quatre octets, douze une fois encodés par le SDK),
     * est servi, et ce que le SDK en met en file dans la session — calcul de
     * RouteBackedToolsTest : la réponse JSON-RPC, réencodée par
     * `Session::save()` — reste sous la borne de la session, sans quoi
     * celle-ci serait détruite et le client ne recevrait rien.
     */
    public function testAPayloadAtTheBoundFitsInTheSession(): void
    {
        $held = $this->holdMcpConnection();
        $corpus = $this->corpusOfPayloadBytes(GetNominativeCorpusTool::PAYLOAD_MAX_BYTES);
        $this->useRenderer(new StubCorpusRenderer($corpus));
        $sessionId = $this->initializeMcpSession($this->client, self::bearer($held->accessToken));

        $this->postMcp($this->client, $sessionId, $this->callMessage(['locale' => 'fr']), self::bearer($held->accessToken));

        $answer = $this->rawMcpAnswer($this->client);
        self::assertNotSame('', $answer, 'La réponse n\'est pas arrivée : la session a-t-elle été détruite ?');
        $result = $this->decodeMcpResponse($this->client)['result'] ?? null;
        self::assertIsArray($result);
        self::assertFalse($result['isError'] ?? false);
        self::assertSame($corpus, $result['structuredContent']['corpus'] ?? null);
        $queuedBytes = \strlen(json_encode($answer, \JSON_THROW_ON_ERROR));
        self::assertLessThanOrEqual(BoundedSessionStore::MAX_BYTES, $queuedBytes, \sprintf('%d octets en file (réponse de %d octets), borne de session %d.', $queuedBytes, \strlen($answer), BoundedSessionStore::MAX_BYTES));
    }

    /**
     * Un caractère de plus que la borne : une erreur d'outil qui donne la page
     * du site où se télécharge le CV, un `warning` aux tailles seules, rien du
     * corpus, et aucune ligne d'audit — rien n'a été servi.
     */
    public function testAnOversizedCorpusIsRefusedWithTheCvPageLink(): void
    {
        $held = $this->holdMcpConnection();
        $corpus = $this->corpusOfPayloadBytes(GetNominativeCorpusTool::PAYLOAD_MAX_BYTES).self::WIDEST_CHARACTER;
        $this->useRenderer(new StubCorpusRenderer($corpus));
        $sessionId = $this->initializeMcpSession($this->client, self::bearer($held->accessToken));

        $result = $this->toolResult($sessionId, ['locale' => 'en'], bearer: self::bearer($held->accessToken));

        self::assertTrue($result['isError']);
        self::assertArrayNotHasKey('structuredContent', $result);
        self::assertSame([['type' => 'text', 'text' => 'The nominative corpus is too large to be served over MCP. The CV can be downloaded from the site: '.$this->frontendBaseUrl().'/en/assistant']], $result['content']);
        $answer = $this->rawMcpAnswer($this->client);
        self::assertStringNotContainsString(self::WIDEST_CHARACTER, $answer);
        self::assertStringNotContainsStringIgnoringCase('\\ud83d', $answer);
        self::assertSame([], self::securityAuditEvents('nominative-corpus-read'));

        $warnings = array_values(array_filter(
            self::allChannelsLogRecords(),
            static fn (LogRecord $record): bool => Level::Warning === $record->level && str_starts_with($record->message, 'Nominative corpus refused'),
        ));
        self::assertCount(1, $warnings);
        self::assertSame([
            'payloadBytes' => $this->sdkEncodedPayloadBytes($corpus, 'en'),
            'maxBytes' => GetNominativeCorpusTool::PAYLOAD_MAX_BYTES,
        ], $warnings[0]->context);
        foreach (self::allChannelsLogRecords() as $record) {
            self::assertStringNotContainsString(self::WIDEST_CHARACTER, $record->message.json_encode($record->context, \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR));
        }
    }

    /**
     * Le plus long corpus de caractères de quatre octets dont le payload,
     * encodé comme le SDK l'encode (sans drapeau), tient dans `$bound` octets :
     * un caractère de plus le fait dépasser.
     */
    private function corpusOfPayloadBytes(int $bound): string
    {
        $envelope = $this->sdkEncodedPayloadBytes('', 'fr');
        $perCharacter = $this->sdkEncodedPayloadBytes(self::WIDEST_CHARACTER, 'fr') - $envelope;
        self::assertSame(12, $perCharacter, 'Une paire de substitution échappée : douze octets.');

        $corpus = str_repeat(self::WIDEST_CHARACTER, intdiv($bound - $envelope, $perCharacter));
        self::assertLessThanOrEqual($bound, $this->sdkEncodedPayloadBytes($corpus, 'fr'));
        self::assertGreaterThan($bound, $this->sdkEncodedPayloadBytes($corpus.self::WIDEST_CHARACTER, 'fr'));

        return $corpus;
    }

    private function sdkEncodedPayloadBytes(string $corpus, string $locale): int
    {
        return \strlen(json_encode(['corpus' => $corpus, 'cvPageUrl' => $this->frontendBaseUrl().'/'.$locale.'/assistant'], \JSON_THROW_ON_ERROR));
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
     * disent rien du CV) dans `$body`.
     */
    private function assertNothingOfTheCorpusIn(string $body): void
    {
        $cvText = new PopplerPdfTextExtractor(self::FICTIONAL_CV, new ExtractedTextNormalizer(), new NullLogger())->extract();
        self::assertIsString($cvText, 'Le CV fictif doit donner un texte.');

        $lines = preg_split('/\R/u', $cvText);
        self::assertIsArray($lines);
        $checked = 0;
        foreach ($lines as $line) {
            $line = trim($line);
            if (mb_strlen($line) >= 12) {
                ++$checked;
                self::assertStringNotContainsString($line, $body);
                self::assertStringNotContainsString(trim(json_encode($line, \JSON_THROW_ON_ERROR), '"'), $body);
            }
        }
        self::assertGreaterThan(0, $checked, 'Aucune ligne du CV fictif à chercher.');
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

    private function frontendBaseUrl(): string
    {
        return rtrim(self::getContainer()->getParameter('app.frontend_base_url'), '/');
    }
}
