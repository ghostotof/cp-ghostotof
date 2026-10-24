<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection;

use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Infrastructure\League\OAuthEncryptionKey;
use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Support\HttpJson;
use App\Tests\Support\TestCredentials;
use Defuse\Crypto\Crypto;
use Defuse\Crypto\Key;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le parcours OAuth de la connexion MCP (#647) par HTTP, pour les tests
 * fonctionnels qui le traversent : un client enregistré, un compte connecté
 * par cookie (XSRF compris), la demande d'autorisation, le consentement, le
 * code, puis le point de jeton.
 *
 * Écrit une fois pour le consentement, le jeton, la révocation et le
 * parcours complet : un paramètre de la demande changé ici change pour tous.
 *
 * Les URL attendues sont recalculées depuis les paramètres de configuration,
 * jamais lues dans `OAuthEndpoints` : un test resterait vert sur une URL fausse
 * qu'il recopierait. Les mots de passe viennent de TestCredentials, jamais
 * d'un littéral.
 *
 * La classe qui l'utilise appelle setUpOAuthFlow() et tearDownOAuthFlow().
 */
trait DrivesTheOAuthFlow
{
    use HttpJson;

    /** Les tables du modèle, des feuilles vers la racine, puis les comptes. */
    private const array OAUTH_TABLES = ['mcp_refresh_token', 'mcp_access_token', 'mcp_authorization_code', 'mcp_connection', 'mcp_client', 'cpg_user'];

    private const string CONSENT_PATH = '/api/oauth/consent';
    private const string AUTHORIZE_PATH = '/api/oauth/authorize';
    private const string TOKEN_PATH = '/api/oauth/token';

    private const string CLIENT_ID = 'https://client.example.test/metadata.json';

    /** Un second client enregistré, pour les jetons émis à un autre que l'appelant. */
    private const string OTHER_CLIENT_ID = 'https://other-client.example.test/metadata.json';

    private const string CLIENT_NAME = 'Client de test';

    private const string LOOPBACK_REDIRECT = 'http://localhost:49152/callback';

    /** Un vérificateur PKCE conforme à RFC 7636 (43 caractères au moins). */
    private const string CODE_VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk-test';

    private const string TRUSTED_USERNAME = 'trusted';

    /** Le nom OAuth d'un paramètre de la demande, et son nom dans le corps JSON du consentement. */
    private const array CONSENT_BODY_NAMES = [
        'response_type' => 'responseType',
        'client_id' => 'clientId',
        'redirect_uri' => 'redirectUri',
        'scope' => 'scope',
        'state' => 'state',
        'code_challenge' => 'codeChallenge',
        'code_challenge_method' => 'codeChallengeMethod',
        'resource' => 'resource',
    ];

    private KernelBrowser $client;

    private function setUpOAuthFlow(): void
    {
        $this->client = self::createClient();
        $this->purge();
        // `login_throttling` compte dans cache.rate_limiter, en base (ADR 0005)
        // et partagé d'un test à l'autre : chaque test repart de zéro.
        self::getContainer()->get('cache.rate_limiter')->clear();
        $clients = self::getContainer()->get(McpClientRepositoryInterface::class);
        $clients->add(RegisteredMcpClient::of(self::CLIENT_ID, self::CLIENT_NAME, ['http://localhost/callback', 'https://client.example.test/callback']));
        $clients->add(RegisteredMcpClient::of(self::OTHER_CLIENT_ID, 'Autre client', ['http://localhost/callback']));
    }

    private function tearDownOAuthFlow(): void
    {
        $this->purge();
    }

    /**
     * Le code d'autorisation qu'un compte de confiance accorde à CLIENT_ID :
     * point d'autorisation, consentement, puis le `code` lu sur la
     * redirection vers le client. Le compte reste connecté (cookies).
     *
     * @param array<string, string|null> $overrides paramètres de la demande, sous leur nom OAuth
     */
    private function grantedCode(array $overrides = []): string
    {
        $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);

        $this->client->request('GET', self::AUTHORIZE_PATH.'?'.http_build_query($this->validParameters($overrides)));
        self::assertSame(Response::HTTP_FOUND, $this->client->getResponse()->getStatusCode(), substr((string) $this->client->getResponse()->getContent(), 0, 500));

        $response = $this->decide(approved: true, overrides: $overrides);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        $code = $this->queryOf($this->redirectUriOf($response))['code'] ?? null;
        self::assertIsString($code);
        self::assertNotSame('', $code);

        return $code;
    }

    /**
     * L'échange du code au point de jeton, avec les paramètres d'un client
     * honnête ; `$overrides` les remplace (`null` retire le paramètre).
     *
     * @param array<string, string|null> $overrides
     */
    private function exchange(string $code, array $overrides = []): Response
    {
        return $this->postForm(self::TOKEN_PATH, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::LOOPBACK_REDIRECT,
            'client_id' => self::CLIENT_ID,
            'code_verifier' => self::CODE_VERIFIER,
            'resource' => $this->resource(),
            ...$overrides,
        ]);
    }

    /**
     * Un rafraîchissement au point de jeton.
     *
     * @param array<string, string|null> $overrides
     */
    private function refresh(string $refreshToken, array $overrides = []): Response
    {
        return $this->postForm(self::TOKEN_PATH, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => self::CLIENT_ID,
            ...$overrides,
        ]);
    }

    /**
     * Un POST `application/x-www-form-urlencoded`, comme un client OAuth
     * l'envoie. `$parameters` en tableau (`null` retire le paramètre), ou le
     * corps brut, pour un paramètre répété : PHP n'en lit que la dernière
     * occurrence, que les paramètres analysés reprennent comme il le ferait.
     *
     * @param array<string, string|null>|string $parameters
     * @param array<string, string>             $server
     */
    private function postForm(string $path, array|string $parameters, array $server = []): Response
    {
        $body = \is_string($parameters) ? $parameters : http_build_query(array_filter($parameters, static fn(?string $value): bool => null !== $value));
        parse_str($body, $parsed);

        $this->client->request('POST', $path, $parsed, server: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_ACCEPT' => 'application/json', ...$server], content: $body);

        return $this->client->getResponse();
    }

    /**
     * Le refus d'un point OAuth : le JSON de RFC 6749 (`error`, et l'indication
     * de league), jamais un problem+json, jamais une redirection ni un cache.
     */
    private function assertOAuthError(Response $response, int $status, string $error): void
    {
        self::assertSame($status, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        self::assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        self::assertFalse($response->headers->has('Location'));
        $this->assertNotStored($response);
        $body = $this->json($response);
        self::assertSame($error, $body['error'] ?? null, (string) $response->getContent());
        self::assertArrayNotHasKey('type', $body);
    }

    /**
     * @param array<string, string|null> $overrides paramètres de la demande, sous leur nom OAuth
     */
    private function decide(bool $approved, array $overrides = []): Response
    {
        $this->postConsent(['approved' => $approved, ...$this->decisionBody($overrides)], $this->xsrfToken());

        return $this->client->getResponse();
    }

    /**
     * La demande telle que la page la renvoie : les paramètres validés reçus
     * du point d'autorisation, sous les noms du corps JSON.
     *
     * @param array<string, string|null> $overrides
     *
     * @return array<string, mixed>
     */
    private function decisionBody(array $overrides = []): array
    {
        return $this->asDecisionBody($this->validParameters($overrides));
    }

    /**
     * Des paramètres de la demande sous leur nom OAuth, renommés comme la page
     * les renvoie dans le corps JSON (`consentParamsFromQuery()`, côté
     * frontend). Un nom que la page ne lit pas fait échouer le test.
     *
     * @param array<int|string, mixed> $parameters
     *
     * @return array<string, mixed>
     */
    private function asDecisionBody(array $parameters): array
    {
        $body = [];

        foreach ($parameters as $name => $value) {
            $bodyName = self::CONSENT_BODY_NAMES[$name] ?? self::fail(\sprintf('Paramètre inattendu dans la demande : %s.', $name));
            $body[$bodyName] = $value;
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postConsent(array $body, ?string $xsrfToken): void
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        if (null !== $xsrfToken) {
            $server['HTTP_X_XSRF_TOKEN'] = $xsrfToken;
        }

        $this->client->request('POST', self::CONSENT_PATH, server: $server, content: self::jsonBody($body));
    }

    /**
     * @param array<string, string|null> $overrides
     *
     * @return array<string, string>
     */
    private function validParameters(array $overrides = []): array
    {
        $parameters = [
            'response_type' => 'code',
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => self::LOOPBACK_REDIRECT,
            'scope' => 'nominative:read',
            'state' => 'state-1',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', self::CODE_VERIFIER, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'resource' => $this->resource(),
            ...$overrides,
        ];

        return array_filter($parameters, static fn(?string $value): bool => null !== $value);
    }

    /**
     * Crée le compte et le connecte ; renvoie son UUID.
     *
     * @param list<string> $roles
     */
    private function loginAs(string $username, array $roles): string
    {
        $password = [] === $roles ? TestCredentials::plainPassword() : TestCredentials::superPassword();
        $account = self::getContainer()->get(CpgUserRegistrarInterface::class)->register($username, $password, $roles);

        $this->client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'fetch'], content: self::jsonBody([
            'username' => $username,
            'password' => $password,
        ]));
        self::assertResponseIsSuccessful();

        return $account->getId()->toRfc4122();
    }

    private function xsrfToken(): string
    {
        return $this->client->getCookieJar()->get('XSRF-TOKEN')?->getValue() ?? self::fail('Aucun cookie XSRF-TOKEN.');
    }

    private function redirectUriOf(Response $response): string
    {
        $body = $this->json($response);
        self::assertSame(['redirectUri'], array_keys($body));
        self::assertIsString($body['redirectUri']);

        return $body['redirectUri'];
    }

    /**
     * @return array<int|string, mixed>
     */
    private function queryOf(string $uri): array
    {
        parse_str((string) parse_url($uri, \PHP_URL_QUERY), $query);

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        /** @var array<string, mixed> $body */
        return $body;
    }

    /**
     * @param array<string, string> $parameters
     */
    private function countRows(string $sql, array $parameters = []): int
    {
        $count = self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne($sql, $parameters);
        self::assertIsInt($count);

        return $count;
    }

    private function assertNotStored(Response $response): void
    {
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'), (string) $response->headers->get('Cache-Control'));
    }

    /**
     * La charge d'un code ou d'un jeton de rafraîchissement, déchiffrée sous
     * la clé de league : ce que le client détient, que la base ne doit pas
     * garder.
     *
     * @return array<string, mixed>
     */
    private function payloadOf(string $encrypted): array
    {
        $payload = json_decode(Crypto::decrypt($encrypted, $this->encryptionKey()), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /** La clé de league, dérivée d'`APP_SECRET` comme le conteneur la dérive. */
    private function encryptionKey(): Key
    {
        return OAuthEncryptionKey::fromSecret(self::getContainer()->getParameter('kernel.secret'));
    }

    private function resource(): string
    {
        return $this->publicApiBase().'/api/mcp';
    }

    /**
     * La base publique de l'API telle que le conteneur de test la résout
     * (`phpunit.dist.xml` la force à vide : elle retombe sur celle du
     * frontend), barre finale retirée.
     */
    private function publicApiBase(): string
    {
        $base = self::getContainer()->getParameter('app.public_api_base_url');
        self::assertIsString($base);
        self::assertNotSame('', $base);

        return rtrim($base, '/');
    }

    private function purge(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $em->getConnection();
        foreach (self::OAUTH_TABLES as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
        $em->clear();
    }
}
