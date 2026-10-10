<?php

declare(strict_types=1);

namespace App\Tests\Ai\Translation\Presentation\ApiResource;

use App\Ai\Translation\Presentation\ApiResource\BackofficeTranslationResource;
use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Shared\Presentation\ApiResource\InputContradictsValidationException;
use App\Tests\Support\HttpJson;
use App\Tests\Support\ReadsAiUsageLog;
use App\Tests\Support\ReadsAllChannelsLog;
use App\Tests\Support\TestCredentials;
use ArrayObject;
use Closure;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Monolog\Formatter\JsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Couvre POST /api/backoffice/translations (spec 0002, M3). Aucun test ne
 * sort sur le réseau : le client HTTP du bridge Anthropic est remplacé par un
 * MockHttpClient qui rend une réponse au format de l'API Messages — le vrai
 * bridge (en-têtes, corps, conversion) est donc exercé, pas contourné.
 */
final class BackofficeTranslationResourceTest extends WebTestCase
{
    use HttpJson;
    use ReadsAiUsageLog;
    use ReadsAllChannelsLog;

    private const string SUPER_USERNAME = 'super';
    private const string PLAIN_USERNAME = 'jane';
    private const string OTHER_SUPER_USERNAME = 'other-super';
    private const string PATH = '/api/backoffice/translations';

    /** Doit refléter rate_limiter.yaml (translation_assistant.limit). */
    private const int QUOTA = 30;

    /** Service concret derrière le client scoped `ai.http_client` (cf. framework.yaml). */
    private const string AI_HTTP_CLIENT_INNER = 'ai.http_client.scoping.inner';

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
     * `validatedFields()` rend le dictionnaire tel que la validation le
     * garantit. Une valeur non textuelle à ce stade est un défaut du pipeline
     * (issue #338) : refusée sous un nom dédié, qui nomme le champ, plutôt que
     * filtrée en silence. Le DTO est construit sans passer par le validateur.
     */
    public function testANonTextualFieldContradictsTheValidationAndIsNamed(): void
    {
        $this->expectException(InputContradictsValidationException::class);
        $this->expectExceptionMessage('"summary"');

        new BackofficeTranslationResource(sourceLocale: 'fr', targetLocale: 'en', fields: ['title' => 'Bonjour', 'summary' => ['imbriqué']])->validatedFields();
    }

    public function testAnonymousRequestIsRejected(): void
    {
        $client = self::createClient();

        $client->request('POST', self::PATH, server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody($this->validPayload()));

        // 403 et non 401 : sur une mutation, le double-submit CSRF
        // (CsrfCookieRequestSubscriber, priorité 20) tranche avant même le
        // firewall. ApiRouteExposureTest accepte l'un ou l'autre — ce qui
        // compte, c'est qu'un anonyme n'atteigne jamais le processeur.
        self::assertResponseStatusCodeSame(403);
    }

    public function testBaseTierTokenIsForbidden(): void
    {
        $client = self::createClient();
        $csrfToken = $this->obtainBaseAccess($client);

        $this->post($client, $csrfToken, $this->validPayload());

        self::assertResponseStatusCodeSame(403);
    }

    public function testAuthenticatedAccountWithoutRoleSuperIsForbidden(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::PLAIN_USERNAME, TestCredentials::plainPassword());
        $csrfToken = $this->loginAs($client, self::PLAIN_USERNAME, TestCredentials::plainPassword());

        $this->post($client, $csrfToken, $this->validPayload());

        self::assertResponseStatusCodeSame(403);
    }

    public function testMutationWithoutCsrfHeaderIsForbidden(): void
    {
        $client = $this->superClient();

        $client->request('POST', self::PATH, server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody($this->validPayload()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testRoleSuperGetsTheTranslatedFieldsBack(): void
    {
        $client = $this->superClient();
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());
        $captured = $this->stubAnthropic($client, $this->anthropicMessage('{"title":"RabbitMQ broker outage","impact":"The contact form answered 500."}'));

        $this->post($client, $csrfToken, $this->validPayload());

        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('fr', $body['sourceLocale']);
        self::assertSame('en', $body['targetLocale']);
        self::assertSame([
            'title' => 'RabbitMQ broker outage',
            'impact' => 'The contact form answered 500.',
        ], $body['fields']);

        // Le vrai bridge a bien été traversé : modèle, plafond de jetons et
        // schéma strict sont dans la requête sortante, la clé factice en en-tête.
        $request = $captured['body'];
        self::assertIsArray($request);
        self::assertSame('claude-sonnet-5', $request['model']);
        self::assertSame(4096, $request['max_tokens']);
        self::assertSame('json_schema', $request['output_config']['format']['type']);
        self::assertSame(['title', 'impact'], $request['output_config']['format']['schema']['required']);
        self::assertFalse($request['output_config']['format']['schema']['additionalProperties']);
        self::assertArrayNotHasKey('temperature', $request);
        self::assertSame('sk-ant-test-not-a-real-key', $captured['apiKey'] ?? null);
    }

    /**
     * Issue #356 : l'usage du traducteur (jetons, durée) se relit en
     * production, où LOG_LEVEL=warning écarterait un `info` du canal
     * applicatif. Il part donc sur `ai_usage`, comme celui de l'assistant de
     * parcours — jamais le texte traduit.
     */
    public function testTheUsageIsLoggedOnTheDedicatedChannel(): void
    {
        $client = $this->superClient();
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());
        $this->stubAnthropic($client, $this->anthropicMessage('{"title":"RabbitMQ broker outage","impact":"The contact form answered 500."}'));

        $this->post($client, $csrfToken, $this->validPayload());

        self::assertResponseStatusCodeSame(200);
        $done = self::aiUsageRecordsWithOutcome('done');
        self::assertCount(1, $done);
        self::assertSame(120, $done[0]->context['promptTokens'] ?? null);
        self::assertSame(30, $done[0]->context['completionTokens'] ?? null);
        self::assertSame(2, $done[0]->context['fieldCount'] ?? null);
        self::assertStringNotContainsString('RabbitMQ', (new JsonFormatter())->format($done[0]));
    }

    /**
     * La panne du fournisseur part sur le même canal : une requête sur
     * `ai_usage` voit toutes les fins du traducteur, comme de l'assistant.
     */
    public function testAProviderFailureIsLoggedOnTheDedicatedChannel(): void
    {
        $client = $this->superClient();
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());
        $this->stubAnthropic($client, new MockResponse('{"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}', ['http_code' => 529]));

        $this->post($client, $csrfToken, $this->validPayload());

        self::assertResponseStatusCodeSame(503);
        self::assertCount(1, self::aiUsageRecordsWithOutcome('error'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidPayloads(): iterable
    {
        yield 'locale source inconnue' => [['sourceLocale' => 'de', 'targetLocale' => 'en', 'fields' => ['title' => 'x']]];
        yield 'locale cible inconnue' => [['sourceLocale' => 'fr', 'targetLocale' => 'es', 'fields' => ['title' => 'x']]];
        yield 'locales identiques' => [['sourceLocale' => 'fr', 'targetLocale' => 'fr', 'fields' => ['title' => 'x']]];
        yield 'dictionnaire vide' => [['sourceLocale' => 'fr', 'targetLocale' => 'en', 'fields' => []]];
        yield 'dictionnaire absent' => [['sourceLocale' => 'fr', 'targetLocale' => 'en']];
        yield 'trop de champs' => [['sourceLocale' => 'fr', 'targetLocale' => 'en', 'fields' => array_fill_keys(array_map(static fn (int $i): string => 'field'.$i, range(1, 13)), 'x')]];
        yield 'nom de champ invalide' => [['sourceLocale' => 'fr', 'targetLocale' => 'en', 'fields' => ['root-cause' => 'x']]];
        yield 'nom de champ commençant par un chiffre' => [['sourceLocale' => 'fr', 'targetLocale' => 'en', 'fields' => ['1title' => 'x']]];
        yield 'nom de champ trop long' => [['sourceLocale' => 'fr', 'targetLocale' => 'en', 'fields' => [str_repeat('a', 41) => 'x']]];
        yield 'valeur vide' => [['sourceLocale' => 'fr', 'targetLocale' => 'en', 'fields' => ['title' => '   ']]];
        // Issue #159 : jamais un 500, quelle que soit la forme de la valeur.
        yield 'valeur non textuelle : liste' => [['sourceLocale' => 'fr', 'targetLocale' => 'en', 'fields' => ['title' => ['x']]]];
        yield 'valeur non textuelle : objet' => [['sourceLocale' => 'fr', 'targetLocale' => 'en', 'fields' => ['title' => ['nested' => 'x']]]];
        yield 'valeur non textuelle : entier' => [['sourceLocale' => 'fr', 'targetLocale' => 'en', 'fields' => ['title' => 123]]];
        yield 'valeur non textuelle : booléen' => [['sourceLocale' => 'fr', 'targetLocale' => 'en', 'fields' => ['title' => true]]];
        yield 'valeur nulle' => [['sourceLocale' => 'fr', 'targetLocale' => 'en', 'fields' => ['title' => null]]];
        yield 'valeur trop longue' => [['sourceLocale' => 'fr', 'targetLocale' => 'en', 'fields' => ['title' => str_repeat('a', 20_001)]]];
        yield 'total trop long' => [['sourceLocale' => 'fr', 'targetLocale' => 'en', 'fields' => ['a' => str_repeat('a', 20_000), 'b' => str_repeat('b', 20_000), 'c' => 'c']]];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('invalidPayloads')]
    public function testInvalidPayloadIsRejectedBeforeAnyProviderCall(array $payload): void
    {
        $client = $this->superClient();
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());
        $client->getContainer()->set(self::AI_HTTP_CLIENT_INNER, new MockHttpClient(
            static fn (): never => throw new LogicException('Aucun appel au fournisseur ne doit partir sur une requête invalide.'),
        ));

        $this->post($client, $csrfToken, $payload);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * Une valeur non textuelle donne exactement une violation, sur le champ
     * fautif : avec `Sequentially`, `Type` arrête la chaîne avant que `Length`
     * n'ajoute la sienne, générique (« This value should be of type string »).
     */
    public function testANonTextualValueYieldsASingleViolationOnItsField(): void
    {
        $client = $this->superClient();
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $this->post($client, $csrfToken, ['sourceLocale' => 'fr', 'targetLocale' => 'en', 'fields' => ['title' => ['x']]]);

        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertCount(1, $body['violations']);
        self::assertSame('fields[title]', $body['violations'][0]['propertyPath']);
    }

    public function testProviderFailureIsA503WithAStableProblemType(): void
    {
        $client = $this->superClient();
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());
        $this->stubAnthropic($client, new MockResponse('{"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}', ['http_code' => 529]));

        $this->post($client, $csrfToken, $this->validPayload());

        self::assertResponseStatusCodeSame(503);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('/errors/translation-unavailable', $body['type']);
        self::assertStringNotContainsString('Overloaded', (string) $client->getResponse()->getContent());
    }

    /**
     * Issue #269 : le bridge recopie le message d'erreur du fournisseur dans
     * celui de son exception, et ce message peut citer l'entrée — du contenu
     * du backoffice. Aucun journal ne doit le laisser sortir : ni notre log
     * métier, ni ceux du noyau (ErrorListener, canal `request`) et d'API
     * Platform (canal `app`), qui sérialisent toute la chaîne `previous`. La
     * sonde écoute tous les canaux sauf `event` (ReadsAllChannelsLog).
     */
    public function testNoProviderErrorMessageReachesAnyLog(): void
    {
        $client = $this->superClient();
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());
        $this->stubAnthropic($client, new MockResponse(
            '{"type":"error","error":{"type":"invalid_request_error","message":"SENTINELLE-FOURNISSEUR Panne du broker RabbitMQ"}}',
            ['http_code' => 400],
        ));

        $this->post($client, $csrfToken, $this->validPayload());

        self::assertResponseStatusCodeSame(503);
        $records = self::allChannelsLogRecords();
        // Garde-fou : la sonde a bien vu passer l'exception (sinon le test
        // resterait vert sans rien vérifier). `warning` depuis l'issue #348
        // (framework.exceptions), `critical` avant.
        self::assertNotEmpty(array_filter(
            $records,
            static fn (LogRecord $record): bool => Level::Warning === $record->level && str_contains($record->message, 'TranslationUnavailableException'),
        ));
        $formatter = new JsonFormatter();
        foreach ($records as $record) {
            self::assertStringNotContainsString('SENTINELLE-FOURNISSEUR', $formatter->format($record));
        }
    }

    /**
     * Le type d'erreur Anthropic est lu dans le message de l'exception du
     * bridge, dont le format n'est pas une API : ce test le fait passer par
     * le vrai ResultConverter, et rougit si une montée de version le change.
     * Cas typique : le modèle configuré a été retiré (404 `not_found_error`).
     */
    public function testTheProviderErrorTypeIsLoggedThroughTheRealBridge(): void
    {
        $client = $this->superClient();
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());
        $this->stubAnthropic($client, new MockResponse(
            '{"type":"error","error":{"type":"not_found_error","message":"SENTINELLE-FOURNISSEUR model: claude-sonnet-5"}}',
            ['http_code' => 404, 'response_headers' => ['content-type' => 'application/json']],
        ));

        $this->post($client, $csrfToken, $this->validPayload());

        self::assertResponseStatusCodeSame(503);
        $failures = array_values(array_filter(
            self::allChannelsLogRecords(),
            static fn (LogRecord $record): bool => \array_key_exists('providerErrorType', $record->context),
        ));
        self::assertCount(1, $failures);
        self::assertSame('not_found_error', $failures[0]->context['providerErrorType'] ?? null);
        self::assertStringNotContainsString('SENTINELLE-FOURNISSEUR', (new JsonFormatter())->format($failures[0]));
    }

    public function testAResponseOutsideTheSchemaIsA503(): void
    {
        $client = $this->superClient();
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());
        $this->stubAnthropic($client, $this->anthropicMessage('{"title":"RabbitMQ broker outage"}'));

        $this->post($client, $csrfToken, $this->validPayload());

        self::assertResponseStatusCodeSame(503);
    }

    public function testTheThirtyFirstCallInTheHourIsRateLimitedWithRetryAfter(): void
    {
        $client = $this->superClient();
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());
        $this->stubAnthropicRepeatedly($client, fn (): MockResponse => $this->anthropicMessage('{"title":"a","impact":"b"}'));

        for ($i = 0; $i < self::QUOTA; ++$i) {
            $this->post($client, $csrfToken, $this->validPayload());
            self::assertResponseStatusCodeSame(200, \sprintf('Appel n°%d', $i + 1));
        }

        $this->post($client, $csrfToken, $this->validPayload());

        self::assertResponseStatusCodeSame(429);
        self::assertResponseHasHeader('Retry-After');
        self::assertGreaterThan(0, (int) $client->getResponse()->headers->get('Retry-After'));
        // Issue #356 : le refus est tracé sur le canal qui sort des pods de
        // production, avec le compte — la boucle d'un compte ROLE_SUPER
        // compromis se voit.
        $refusals = self::aiUsageRecordsWithOutcome('rate-limited');
        self::assertCount(1, $refusals);
        self::assertSame(self::SUPER_USERNAME, $refusals[0]->context['account'] ?? null);
        self::assertIsString($refusals[0]->context['retryAfter'] ?? null);
    }

    public function testAnInvalidPayloadDoesNotConsumeTheQuota(): void
    {
        $client = $this->superClient();
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());
        $this->stubAnthropicRepeatedly($client, fn (): MockResponse => $this->anthropicMessage('{"title":"a","impact":"b"}'));

        for ($i = 0; $i < self::QUOTA; ++$i) {
            $this->post($client, $csrfToken, ['sourceLocale' => 'fr', 'targetLocale' => 'fr', 'fields' => ['title' => 'x']]);
            self::assertResponseStatusCodeSame(422);
        }

        $this->post($client, $csrfToken, $this->validPayload());

        self::assertResponseStatusCodeSame(200);
    }

    public function testAProviderFailureStillConsumesTheQuota(): void
    {
        $client = $this->superClient();
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());
        $this->stubAnthropicRepeatedly($client, static fn (): MockResponse => new MockResponse('{"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}', ['http_code' => 529]));

        for ($i = 0; $i < self::QUOTA; ++$i) {
            $this->post($client, $csrfToken, $this->validPayload());
            self::assertResponseStatusCodeSame(503);
        }

        $this->post($client, $csrfToken, $this->validPayload());

        // L'appel a eu lieu (et a coûté) : il compte, même en échec.
        self::assertResponseStatusCodeSame(429);
    }

    public function testTheQuotaIsPerAccountNotShared(): void
    {
        $client = $this->superClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::OTHER_SUPER_USERNAME, TestCredentials::variant('other-super'), [CpgUser::ROLE_SUPER]);
        $this->stubAnthropicRepeatedly($client, fn (): MockResponse => $this->anthropicMessage('{"title":"a","impact":"b"}'));

        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());
        for ($i = 0; $i <= self::QUOTA; ++$i) {
            $this->post($client, $csrfToken, $this->validPayload());
        }
        self::assertResponseStatusCodeSame(429);

        $csrfToken = $this->loginAs($client, self::OTHER_SUPER_USERNAME, TestCredentials::variant('other-super'));
        $this->post($client, $csrfToken, $this->validPayload());

        self::assertResponseStatusCodeSame(200);
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'sourceLocale' => 'fr',
            'targetLocale' => 'en',
            'fields' => ['title' => 'Panne du broker RabbitMQ', 'impact' => 'Le formulaire de contact a répondu 500.'],
        ];
    }

    private function superClient(): KernelBrowser
    {
        $client = self::createClient();
        // Sans cela, KernelBrowser reconstruit le kernel à chaque requête : le
        // client HTTP substitué après la connexion serait perdu et la requête
        // suivante partirait réellement vers api.anthropic.com (401 → 503).
        $client->disableReboot();
        // Le limiteur translation_assistant est backé par le filesystem
        // (cache.rate_limiter), donc partagé entre tests : repartir de zéro.
        self::getContainer()->get('cache.rate_limiter')->clear();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, TestCredentials::superPassword(), [CpgUser::ROLE_SUPER]);

        return $client;
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

    /** Réponse de l'API Messages d'Anthropic portant un seul bloc de texte. */
    private function anthropicMessage(string $text): MockResponse
    {
        return new MockResponse(self::jsonBody([
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-5',
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => 'end_turn',
            'stop_sequence' => null,
            'usage' => ['input_tokens' => 120, 'output_tokens' => 30],
        ]), ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]);
    }

    /**
     * Remplace le client HTTP du bridge et capture la requête sortante
     * (corps décodé, clé d'API) pour l'inspecter après coup.
     *
     * @return ArrayObject<string, mixed>
     */
    private function stubAnthropic(KernelBrowser $client, MockResponse $response): ArrayObject
    {
        /** @var ArrayObject<string, mixed> $captured */
        $captured = new ArrayObject();
        $client->getContainer()->set(self::AI_HTTP_CLIENT_INNER, new MockHttpClient(
            static function (string $method, string $url, array $options) use ($captured, $response): MockResponse {
                $captured['method'] = $method;
                $captured['url'] = $url;
                $body = $options['body'] ?? null;
                $captured['body'] = \is_string($body) ? json_decode($body, true) : null;
                foreach ($options['headers'] ?? [] as $header) {
                    if (\is_string($header) && str_starts_with(strtolower($header), 'x-api-key:')) {
                        $captured['apiKey'] = trim(substr($header, \strlen('x-api-key:')));
                    }
                }

                return $response;
            },
        ));

        return $captured;
    }

    /**
     * Comme stubAnthropic(), mais rend une réponse neuve à chaque appel — un
     * MockResponse ne se consomme qu'une fois.
     *
     * @param Closure():MockResponse $factory
     */
    private function stubAnthropicRepeatedly(KernelBrowser $client, Closure $factory): void
    {
        $client->getContainer()->set(self::AI_HTTP_CLIENT_INNER, new MockHttpClient(
            static fn (): MockResponse => $factory(),
        ));
    }

    private function obtainBaseAccess(KernelBrowser $client): string
    {
        self::getContainer()->get('cache.rate_limiter')->clear();
        $client->request('POST', '/api/account/base-access', server: ['HTTP_X_REQUESTED_WITH' => 'fetch']);
        self::assertResponseIsSuccessful();

        $csrfCookie = $client->getCookieJar()->get('XSRF-TOKEN');
        self::assertNotNull($csrfCookie);

        return $csrfCookie->getValue();
    }

    private function loginAs(KernelBrowser $client, string $username, string $password): string
    {
        $client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'fetch'], content: self::jsonBody([
            'username' => $username,
            'password' => $password,
        ]));
        self::assertResponseIsSuccessful();

        $csrfCookie = $client->getCookieJar()->get('XSRF-TOKEN');
        self::assertNotNull($csrfCookie);

        return $csrfCookie->getValue();
    }
}
