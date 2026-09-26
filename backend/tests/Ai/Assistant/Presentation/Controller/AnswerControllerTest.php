<?php

declare(strict_types=1);

namespace App\Tests\Ai\Assistant\Presentation\Controller;

use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Support\HttpJson;
use App\Tests\Support\TestCredentials;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * POST /api/assistant/answers (spec 0005 M4). Le vrai
 * bridge Scaleway est traversé : seul le transport est simulé, en flux SSE au
 * format compatible OpenAI. Le client concret du bridge Anthropic est aussi
 * remplacé, par un piège qui compte ses appels : ADR 0004 D3, le corpus ne doit
 * jamais y partir.
 */
final class AnswerControllerTest extends WebTestCase
{
    use HttpJson;

    private const string PATH = '/api/assistant/answers';
    private const string TRUSTED_USERNAME = 'trusted';
    private const string PLAIN_USERNAME = 'plain';
    private const string SUPER_USERNAME = 'super';
    private const string OTHER_TRUSTED_USERNAME = 'other-trusted';

    /** Doit refléter rate_limiter.yaml (career_assistant.limit). */
    private const int QUOTA = 30;

    /** Services concrets derrière les clients scoped (cf. framework.yaml). */
    private const string SCALEWAY_INNER = 'ai.scaleway.http_client.scoping.inner';
    private const string ANTHROPIC_INNER = 'ai.http_client.scoping.inner';

    private const string MODEL = 'mistral-small-3.2-24b-instruct-2506';

    /** @var list<array{url: string, body: mixed}> */
    private array $scalewayRequests = [];

    private int $anthropicCalls = 0;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('DELETE FROM cpg_user');
        parent::tearDown();
    }

    /**
     * 403 et non 401 : sur une mutation, le double-submit CSRF (priorité 20)
     * tranche avant le firewall. ApiRouteExposureTest accepte l'un ou l'autre.
     */
    public function testAnAnonymousRequestIsRefusedByTheCsrfCheckBeforeTheFirewall(): void
    {
        $client = self::createClient();

        $client->request('POST', self::PATH, server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody($this->payload()));

        self::assertResponseStatusCodeSame(403);
    }

    /** Le 401 du firewall lui-même : un XSRF signé, mais aucun cookie d'authentification. */
    public function testAValidCsrfTokenWithoutAnAuthenticationCookieIsUnauthorized(): void
    {
        $client = self::createClient();
        $csrfToken = $this->obtainBaseAccess($client);
        $client->getCookieJar()->expire('BEARER');

        $this->post($client, $csrfToken, $this->payload());

        self::assertResponseStatusCodeSame(401);
    }

    public function testTheBaseTierIsForbidden(): void
    {
        $client = self::createClient();
        $csrfToken = $this->obtainBaseAccess($client);

        $this->post($client, $csrfToken, $this->payload());

        self::assertResponseStatusCodeSame(403);
    }

    public function testARealAccountWithoutRoleTrustedIsForbidden(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::PLAIN_USERNAME, TestCredentials::plainPassword());
        $csrfToken = $this->loginAs($client, self::PLAIN_USERNAME, TestCredentials::plainPassword());

        $this->post($client, $csrfToken, $this->payload());

        self::assertResponseStatusCodeSame(403);
    }

    public function testRoleTrustedWithoutTheCsrfHeaderIsForbidden(): void
    {
        [$client] = $this->trustedClient();

        $client->request('POST', self::PATH, server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody($this->payload()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testRoleTrustedReceivesTheAnswerAsAnEventStream(): void
    {
        [$client, $csrfToken] = $this->trustedClient();
        $this->stubProviders($client, new MockResponse($this->scalewayStream('Il a ', 'conçu des API.'), $this->sseHeaders()));

        $this->post($client, $csrfToken, $this->payload());

        self::assertResponseStatusCodeSame(200);
        $headers = $client->getResponse()->headers;
        self::assertStringStartsWith('text/event-stream', (string) $headers->get('Content-Type'));
        self::assertSame('no', $headers->get('X-Accel-Buffering'));
        self::assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));

        // Le corps diffusé n'est lisible que sur la réponse interne du navigateur
        // de test, qui l'a capturé en l'envoyant.
        $events = $this->events($client->getInternalResponse()->getContent());
        self::assertCount(3, $events);
        self::assertSame(['event' => 'delta', 'data' => ['text' => 'Il a ']], $events[0]);
        self::assertSame(['event' => 'delta', 'data' => ['text' => 'conçu des API.']], $events[1]);
        self::assertSame('done', $events[2]['event']);
        $done = $events[2]['data'];
        self::assertIsArray($done);
        self::assertSame(812, $done['promptTokens']);
        self::assertSame(9, $done['completionTokens']);
        self::assertIsInt($done['durationMs']);
    }

    /**
     * Le coût se relit en production, où LOG_LEVEL=warning écarterait un `info`
     * du canal applicatif : l'usage part sur le canal `ai_usage`, qui a son
     * propre handler à niveau fixe (monolog.yaml).
     */
    public function testTheUsageIsLoggedOnTheDedicatedChannel(): void
    {
        [$client, $csrfToken] = $this->trustedClient();
        $this->stubProviders($client, new MockResponse($this->scalewayStream('Il a ', 'conçu des API.'), $this->sseHeaders()));

        $this->post($client, $csrfToken, $this->payload());
        $client->getInternalResponse();

        $done = array_values(array_filter(
            $this->aiUsageRecords(),
            static fn (LogRecord $record): bool => 'done' === ($record->context['outcome'] ?? null),
        ));
        self::assertCount(1, $done);
        self::assertSame(812, $done[0]->context['promptTokens'] ?? null);
    }

    /** Garde D3 (relecture de #260) : le service résolu parle à Scaleway, jamais à Anthropic. */
    public function testTheAnswerComesFromTheScalewayModelAndNeverFromAnthropic(): void
    {
        [$client, $csrfToken] = $this->trustedClient();
        $this->stubProviders($client, new MockResponse($this->scalewayStream('ok'), $this->sseHeaders()));

        $this->post($client, $csrfToken, $this->payload());

        self::assertResponseStatusCodeSame(200);
        self::assertSame(0, $this->anthropicCalls);
        self::assertCount(1, $this->scalewayRequests);
        self::assertStringStartsWith('https://api.scaleway.ai/', $this->scalewayRequests[0]['url']);

        $body = $this->scalewayRequests[0]['body'];
        self::assertIsArray($body);
        self::assertSame(self::MODEL, $body['model']);
        self::assertTrue($body['stream']);
        self::assertSame(['include_usage' => true], $body['stream_options']);
        $messages = $body['messages'];
        self::assertIsArray($messages);
        self::assertSame('system', $messages[0]['role']);
        self::assertIsString($messages[0]['content']);
        self::assertStringStartsWith('You are the career assistant', $messages[0]['content']);
        self::assertStringContainsString('<documents>', $messages[0]['content']);
        self::assertSame(['user', 'assistant', 'user'], array_column(\array_slice($messages, 1), 'role'));
    }

    public function testRoleSuperInheritsTheAccess(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, TestCredentials::superPassword(), [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());
        $this->stubProviders($client, new MockResponse($this->scalewayStream('ok'), $this->sseHeaders()));

        $this->post($client, $csrfToken, $this->payload());

        self::assertResponseStatusCodeSame(200);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function providerFailuresBeforeTheFirstFragment(): iterable
    {
        yield 'erreur serveur' => [500];
        yield 'refus (projet ou clé)' => [403];
    }

    #[DataProvider('providerFailuresBeforeTheFirstFragment')]
    public function testAProviderFailureBeforeTheFirstFragmentIsA503Problem(int $status): void
    {
        [$client, $csrfToken] = $this->trustedClient();
        $this->stubProviders($client, new MockResponse(
            self::jsonBody(['status' => $status, 'message' => 'refusé']),
            ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']],
        ));

        $this->post($client, $csrfToken, $this->payload());

        self::assertResponseStatusCodeSame(503);
        $problem = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($problem);
        self::assertIsString($problem['type'] ?? null);
        self::assertStringEndsWith('/errors/assistant-unavailable', $problem['type']);
    }

    /** Point de relecture n°1 : une coupure après le 200 se voit, elle ne ressemble pas à une fin. */
    /**
     * Troisième passe, point 9 : le TypeError que lève le vrai bridge sur une
     * ligne valide mais de forme inattendue (`delta.content` en tableau) devient
     * l'événement `error`, pas un flux coupé sans rien dire.
     */
    public function testAnUnexpectedlyShapedProviderLineEndsWithAnErrorEvent(): void
    {
        [$client, $csrfToken] = $this->trustedClient();
        $this->stubProviders($client, new MockResponse(
            $this->chunk(['choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Il a '], 'finish_reason' => null]]])
            .$this->chunk(['choices' => [['index' => 0, 'delta' => ['content' => ['inattendu']], 'finish_reason' => null]]]),
            $this->sseHeaders(),
        ));

        $this->post($client, $csrfToken, $this->payload());

        self::assertResponseStatusCodeSame(200);
        self::assertSame([
            ['event' => 'delta', 'data' => ['text' => 'Il a ']],
            ['event' => 'error', 'data' => ['reason' => 'assistant-unavailable']],
        ], $this->events($client->getInternalResponse()->getContent()));
    }

    public function testAFailureDuringTheStreamEndsWithAnErrorEventAndNoDone(): void
    {
        [$client, $csrfToken] = $this->trustedClient();
        $this->stubProviders($client, new MockResponse([
            $this->chunk(['choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Il a '], 'finish_reason' => null]]]),
            new TransportException('Coupure simulée.'),
        ], $this->sseHeaders()));

        $this->post($client, $csrfToken, $this->payload());

        self::assertResponseStatusCodeSame(200);
        self::assertSame([
            ['event' => 'delta', 'data' => ['text' => 'Il a ']],
            ['event' => 'error', 'data' => ['reason' => 'assistant-unavailable']],
        ], $this->events($client->getInternalResponse()->getContent()));

        // EventSourceHttpClient renverrait sinon la même requête après 10 s :
        // une seconde génération facturée (ReplayRefusingHttpClient).
        self::assertCount(1, $this->scalewayRequests);
    }

    /**
     * Point de relecture n°3 : un corps mal formé est un 4xx, jamais un 500.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformedPayloads(): iterable
    {
        yield 'locale hors liste' => [['locale' => 'de', 'messages' => [['role' => 'user', 'content' => 'x']]]];
        yield 'locale absente' => [['messages' => [['role' => 'user', 'content' => 'x']]]];
        yield 'locale non textuelle' => [['locale' => 5, 'messages' => [['role' => 'user', 'content' => 'x']]]];
        yield 'messages absent' => [['locale' => 'fr']];
        yield 'messages vide' => [['locale' => 'fr', 'messages' => []]];
        yield 'messages non liste' => [['locale' => 'fr', 'messages' => 'x']];
        yield 'élément non objet' => [['locale' => 'fr', 'messages' => ['x']]];
        yield 'rôle absent' => [['locale' => 'fr', 'messages' => [['content' => 'x']]]];
        yield 'rôle inconnu' => [['locale' => 'fr', 'messages' => [['role' => 'system', 'content' => 'x']]]];
        yield 'rôle non textuel' => [['locale' => 'fr', 'messages' => [['role' => ['user'], 'content' => 'x']]]];
        yield 'contenu numérique' => [['locale' => 'fr', 'messages' => [['role' => 'user', 'content' => 42]]]];
        yield 'contenu tableau' => [['locale' => 'fr', 'messages' => [['role' => 'user', 'content' => ['x']]]]];
        yield 'contenu blanc' => [['locale' => 'fr', 'messages' => [['role' => 'user', 'content' => '   ']]]];
        yield 'clé en trop' => [['locale' => 'fr', 'messages' => [['role' => 'user', 'content' => 'x', 'name' => 'y']]]];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('malformedPayloads')]
    public function testAMalformedBodyIsAClientErrorAndNeverReachesTheProvider(array $payload): void
    {
        [$client, $csrfToken] = $this->trustedClient();
        $this->stubProviders($client, new MockResponse($this->scalewayStream('ok'), $this->sseHeaders()));

        $this->post($client, $csrfToken, $payload);

        // 400 ou 422, pas seulement « un 4xx » : un 404 ou un 403 voudrait dire
        // que la requête n'a jamais atteint la validation.
        self::assertContains($client->getResponse()->getStatusCode(), [400, 422]);
        self::assertSame([], $this->scalewayRequests);
    }

    /**
     * Bornes de coût D6 : chaque violation est un 422 typé, rendu avant tout
     * appel au fournisseur.
     *
     * @return iterable<string, array{list<array{role: string, content: string}>}>
     */
    public static function conversationsOutOfBounds(): iterable
    {
        $alternating = static fn (int $count): array => array_map(
            static fn (int $rank): array => ['role' => 0 === $rank % 2 ? 'user' : 'assistant', 'content' => 'Message '.$rank],
            range(0, $count - 1),
        );

        yield '13 messages' => [$alternating(13)];
        yield 'message utilisateur de 1 001 caractères' => [[['role' => 'user', 'content' => str_repeat('a', 1001)]]];
        yield "message de l'assistant de 4 001 caractères" => [[
            ['role' => 'user', 'content' => 'Question ?'],
            ['role' => 'assistant', 'content' => str_repeat('a', 4001)],
            ['role' => 'user', 'content' => 'Question ?'],
        ]];
        // Chaque message sous sa propre borne, le total au-dessus :
        // 5 × 1 000 + 4 × 2 800 = 16 200 caractères.
        yield 'conversation de plus de 16 000 caractères' => [array_map(
            static fn (int $rank): array => 0 === $rank % 2
                ? ['role' => 'user', 'content' => str_repeat('a', 1000)]
                : ['role' => 'assistant', 'content' => str_repeat('a', 2800)],
            range(0, 8),
        )];
        yield "premier message de l'assistant" => [[
            ['role' => 'assistant', 'content' => 'Bonjour.'],
            ['role' => 'user', 'content' => 'Question ?'],
        ]];
        yield 'deux messages utilisateur consécutifs' => [[
            ['role' => 'user', 'content' => 'Première ?'],
            ['role' => 'user', 'content' => 'Seconde ?'],
        ]];
        yield "dernier message de l'assistant" => [[
            ['role' => 'user', 'content' => 'Question ?'],
            ['role' => 'assistant', 'content' => 'Réponse.'],
        ]];
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     */
    #[DataProvider('conversationsOutOfBounds')]
    public function testAConversationOutOfBoundsIsA422ProblemAndNeverReachesTheProvider(array $messages): void
    {
        [$client, $csrfToken] = $this->trustedClient();
        $this->stubProviders($client, new MockResponse($this->scalewayStream('jamais lu'), $this->sseHeaders()));

        $this->post($client, $csrfToken, ['locale' => 'fr', 'messages' => $messages]);

        $response = $client->getResponse();
        self::assertSame(422, $response->getStatusCode());
        $problem = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('/errors/invalid-conversation', $problem['type']);
        self::assertSame([], $this->scalewayRequests);
    }

    public function testTheCallBeyondTheHourlyQuotaIsA429WithRetryAfterAndNeverReachesTheProvider(): void
    {
        [$client, $csrfToken] = $this->trustedClient();
        $this->stubProviders($client, new MockResponse($this->scalewayStream('ok'), $this->sseHeaders()));

        for ($call = 1; $call <= self::QUOTA; ++$call) {
            $this->post($client, $csrfToken, $this->payload());
            self::assertSame(200, $client->getResponse()->getStatusCode(), \sprintf('Appel n°%d refusé avant le quota.', $call));
        }

        $this->post($client, $csrfToken, $this->payload());

        $response = $client->getResponse();
        self::assertSame(429, $response->getStatusCode());
        $problem = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('/errors/rate-limited', $problem['type']);
        $retryAfter = $response->headers->get('Retry-After');
        self::assertNotNull($retryAfter);
        self::assertMatchesRegularExpression('/^\d+$/', $retryAfter);
        self::assertGreaterThan(0, (int) $retryAfter);
        self::assertLessThanOrEqual(3600, (int) $retryAfter);
        self::assertCount(self::QUOTA, $this->scalewayRequests);
    }

    public function testTheQuotaIsKeptPerAccount(): void
    {
        [$client, $csrfToken] = $this->trustedClient();
        $this->stubProviders($client, new MockResponse($this->scalewayStream('ok'), $this->sseHeaders()));
        for ($call = 1; $call <= self::QUOTA + 1; ++$call) {
            $this->post($client, $csrfToken, $this->payload());
        }
        self::assertSame(429, $client->getResponse()->getStatusCode());

        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::OTHER_TRUSTED_USERNAME, TestCredentials::plainPassword(), [CpgUser::ROLE_TRUSTED]);
        $otherCsrfToken = $this->loginAs($client, self::OTHER_TRUSTED_USERNAME, TestCredentials::plainPassword());
        $this->post($client, $otherCsrfToken, $this->payload());

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    /**
     * Une requête refusée par la validation ne coûte rien : après autant de
     * 422 que le quota compte d'appels, un appel valide passe encore.
     */
    public function testARefusedConversationDoesNotConsumeTheQuota(): void
    {
        [$client, $csrfToken] = $this->trustedClient();
        $this->stubProviders($client, new MockResponse($this->scalewayStream('ok'), $this->sseHeaders()));

        for ($call = 1; $call <= self::QUOTA; ++$call) {
            $this->post($client, $csrfToken, ['locale' => 'fr', 'messages' => [
                ['role' => 'user', 'content' => 'Question ?'],
                ['role' => 'assistant', 'content' => 'Réponse.'],
            ]]);
            self::assertSame(422, $client->getResponse()->getStatusCode());
        }

        $this->post($client, $csrfToken, $this->payload());

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    /**
     * La borne de taille ne doit refuser aucune conversation que les bornes
     * D6 acceptent : la plus longue (11 messages, 16 000 caractères au total),
     * écrite tout en caractères de quatre octets, sérialisée comme le fait
     * `JSON.stringify` côté frontend — sans échappement `\u`. Environ 64 Ko ;
     * les 128 Kio laissent la marge d'un contenu échappé (caractères de
     * contrôle en `\u00XX`, six octets chacun).
     */
    public function testTheLongestValidConversationInFourByteCharactersIsAccepted(): void
    {
        [$client, $csrfToken] = $this->trustedClient();
        $this->stubProviders($client, new MockResponse($this->scalewayStream('ok'), $this->sseHeaders()));

        $messages = [];
        for ($rank = 0; $rank < 11; ++$rank) {
            $messages[] = 0 === $rank % 2
                ? ['role' => 'user', 'content' => str_repeat('😀', 1000)]
                : ['role' => 'assistant', 'content' => str_repeat('😀', 2000)];
        }
        $body = json_encode(['locale' => 'fr', 'messages' => $messages], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        self::assertGreaterThan(64_000, \strlen($body));

        $this->postRaw($client, $csrfToken, $body);

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    /**
     * Spec 0005 M4 : au-delà de 128 Kio, le corps est refusé avant d'être
     * désérialisé (nginx, lui, laisse passer jusqu'à 1 Mo). Une conversation
     * valide complétée d'espaces — du JSON toujours valide — mesure la borne
     * à l'octet près.
     */
    public function testABodyOfExactly128KibibytesIsAccepted(): void
    {
        [$client, $csrfToken] = $this->trustedClient();
        $this->stubProviders($client, new MockResponse($this->scalewayStream('ok'), $this->sseHeaders()));

        $this->postRaw($client, $csrfToken, $this->paddedBody(131072));

        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testABodyBeyond128KibibytesIsA413ProblemAndNeverReachesTheProvider(): void
    {
        [$client, $csrfToken] = $this->trustedClient();
        $this->stubProviders($client, new MockResponse($this->scalewayStream('jamais lu'), $this->sseHeaders()));

        $this->postRaw($client, $csrfToken, $this->paddedBody(131073));

        $response = $client->getResponse();
        self::assertSame(413, $response->getStatusCode());
        $problem = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('/errors/request-too-large', $problem['type']);
        self::assertSame([], $this->scalewayRequests);
    }

    /** Issue #77 : un chemin encodé est jugé sur sa forme décodée. */
    public function testAnEncodedPathDoesNotEscapeTheSizeBound(): void
    {
        [$client, $csrfToken] = $this->trustedClient();
        $this->stubProviders($client, new MockResponse($this->scalewayStream('jamais lu'), $this->sseHeaders()));

        $client->request('POST', '/api/%61ssistant/answers', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: $this->paddedBody(131073));

        self::assertSame(413, $client->getResponse()->getStatusCode());
        self::assertSame([], $this->scalewayRequests);
    }

    /**
     * La taille ne se juge qu'une fois l'accès accordé : un anonyme reçoit
     * son refus d'accès, jamais une réponse sur la forme de sa requête.
     */
    public function testAnOversizedBodyFromTheBaseTierIsStillForbidden(): void
    {
        $client = self::createClient();
        $csrfToken = $this->obtainBaseAccess($client);

        $this->postRaw($client, $csrfToken, $this->paddedBody(131073));

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function nonJsonBodies(): iterable
    {
        yield 'formulaire' => ['application/x-www-form-urlencoded', 'locale=fr&messages[0][role]=user&messages[0][content]=Bonjour'];
        yield 'XML' => ['application/xml', '<response><locale>fr</locale><messages><role>user</role><content>Bonjour</content></messages></response>'];
    }

    /**
     * Relecture de sécurité, point 7 : l'endpoint ne parle que JSON. Un autre
     * format n'est pas désérialisé du tout (surface réduite), il est refusé
     * avant d'atteindre le fournisseur.
     */
    #[DataProvider('nonJsonBodies')]
    public function testANonJsonBodyIsUnsupported(string $contentType, string $body): void
    {
        [$client, $csrfToken] = $this->trustedClient();
        $this->stubProviders($client, new MockResponse($this->scalewayStream('jamais lu'), $this->sseHeaders()));

        $client->request('POST', self::PATH, server: [
            'CONTENT_TYPE' => $contentType,
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: $body);

        self::assertSame(415, $client->getResponse()->getStatusCode());
    }

    public function testAnUnparsableBodyIsAClientError(): void
    {
        [$client, $csrfToken] = $this->trustedClient();

        $client->request('POST', self::PATH, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: '{"locale": "fr", "messages": [');

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    /**
     * Les enregistrements du canal `ai_usage`, gardés par le TestHandler que
     * monolog.yaml y branche en test.
     *
     * @return list<LogRecord>
     */
    private function aiUsageRecords(): array
    {
        foreach (self::getContainer()->get('monolog.logger.ai_usage')->getHandlers() as $handler) {
            if ($handler instanceof TestHandler) {
                return array_values($handler->getRecords());
            }
        }

        self::fail('Aucun TestHandler sur le canal ai_usage : voir monolog.yaml (when@test).');
    }

    /** @return array{KernelBrowser, string} */
    private function trustedClient(): array
    {
        $client = self::createClient();
        // Sans cela, le kernel est reconstruit entre la connexion et l'appel :
        // les clients simulés seraient perdus et la requête partirait réellement.
        $client->disableReboot();
        // Le quota vit dans cache.rate_limiter, donc en base (ADR 0005) et
        // partagé d'un test à l'autre : chaque test repart de zéro.
        $client->getContainer()->get('cache.rate_limiter')->clear();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::TRUSTED_USERNAME, TestCredentials::plainPassword(), [CpgUser::ROLE_TRUSTED]);

        return [$client, $this->loginAs($client, self::TRUSTED_USERNAME, TestCredentials::plainPassword())];
    }

    private function stubProviders(KernelBrowser $client, MockResponse $scalewayResponse): void
    {
        $client->getContainer()->set(self::SCALEWAY_INNER, new MockHttpClient(
            function (string $method, string $url, array $options) use ($scalewayResponse): MockResponse {
                $body = $options['body'] ?? null;
                $this->scalewayRequests[] = ['url' => $url, 'body' => \is_string($body) ? json_decode($body, true) : null];

                return $scalewayResponse;
            },
        ));
        $client->getContainer()->set(self::ANTHROPIC_INNER, new MockHttpClient(
            function (): MockResponse {
                ++$this->anthropicCalls;

                return new MockResponse('', ['http_code' => 500]);
            },
        ));
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['locale' => 'fr', 'messages' => [
            ['role' => 'user', 'content' => 'Quel est son domaine ?'],
            ['role' => 'assistant', 'content' => "L'architecture logicielle."],
            ['role' => 'user', 'content' => 'Depuis combien de temps ?'],
        ]];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function post(KernelBrowser $client, string $csrfToken, array $payload): void
    {
        $client->request('POST', self::PATH, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody($payload));
    }

    private function postRaw(KernelBrowser $client, string $csrfToken, string $body): void
    {
        $client->request('POST', self::PATH, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: $body);
    }

    /** Le corps de payload(), complété d'espaces jusqu'à $bytes octets. */
    private function paddedBody(int $bytes): string
    {
        $body = self::jsonBody($this->payload());

        return $body.str_repeat(' ', $bytes - \strlen($body));
    }

    /** Flux Chat Completions compatible OpenAI, tel que Scaleway le diffuse. */
    private function scalewayStream(string ...$fragments): string
    {
        $body = '';
        foreach (array_values($fragments) as $index => $fragment) {
            $delta = 0 === $index ? ['role' => 'assistant', 'content' => $fragment] : ['content' => $fragment];
            $body .= $this->chunk(['choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => null]]]);
        }
        $body .= $this->chunk(['choices' => [['index' => 0, 'delta' => new \stdClass(), 'finish_reason' => 'stop']]]);
        $body .= $this->chunk(['choices' => [], 'usage' => ['prompt_tokens' => 812, 'completion_tokens' => 9, 'total_tokens' => 821]]);

        return $body."data: [DONE]\n\n";
    }

    /** @param array<string, mixed> $data */
    private function chunk(array $data): string
    {
        return 'data: '.json_encode(
            ['id' => 'chatcmpl-test', 'object' => 'chat.completion.chunk', 'created' => 0, 'model' => self::MODEL] + $data,
            \JSON_THROW_ON_ERROR,
        )."\n\n";
    }

    /** @return array{response_headers: array<string, string>} */
    private function sseHeaders(): array
    {
        return ['response_headers' => ['content-type' => 'text/event-stream']];
    }

    /**
     * Événements SSE du corps, `data` décodé.
     *
     * @return list<array{event: string, data: mixed}>
     */
    private function events(string $body): array
    {
        $events = [];
        $blocks = preg_split('/\n\n+/', trim($body));
        if (false === $blocks) {
            self::fail('Corps SSE illisible.');
        }

        foreach ($blocks as $block) {
            $event = 'message';
            $data = [];
            foreach (explode("\n", $block) as $line) {
                if (str_starts_with($line, 'event:')) {
                    $event = trim(substr($line, 6));
                } elseif (str_starts_with($line, 'data:')) {
                    $data[] = ltrim(substr($line, 5));
                }
            }
            $events[] = ['event' => $event, 'data' => json_decode(implode("\n", $data), true, flags: \JSON_THROW_ON_ERROR)];
        }

        return $events;
    }

    private function obtainBaseAccess(KernelBrowser $client): string
    {
        self::getContainer()->get('cache.rate_limiter')->clear();
        $client->request('POST', '/api/account/base-access', server: ['HTTP_X_REQUESTED_WITH' => 'fetch']);
        self::assertResponseIsSuccessful();

        return $client->getCookieJar()->get('XSRF-TOKEN')?->getValue() ?? self::fail('Aucun cookie XSRF-TOKEN.');
    }

    private function loginAs(KernelBrowser $client, string $username, string $password): string
    {
        $client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'fetch'], content: self::jsonBody([
            'username' => $username,
            'password' => $password,
        ]));
        self::assertResponseIsSuccessful();

        return $client->getCookieJar()->get('XSRF-TOKEN')?->getValue() ?? self::fail('Aucun cookie XSRF-TOKEN.');
    }
}
