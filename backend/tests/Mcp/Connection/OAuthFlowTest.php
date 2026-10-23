<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection;

use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Security\User\Domain\Entity\CpgUser;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le parcours OAuth complet de la connexion MCP (#647, spec 0007 phase 2), tel
 * qu'un client MCP le suit : il ne connaît que l'URL du serveur MCP, découvre
 * le serveur d'autorisation par les métadonnées (RFC 9728, puis RFC 8414),
 * envoie la personne au point d'autorisation, reçoit le code après son
 * consentement, l'échange, puis rafraîchit.
 *
 * Les points du serveur d'autorisation (autorisation, jeton, révocation) sont
 * lus dans son document de métadonnées, jamais recopiés : un document qui
 * annoncerait un point faux casserait le parcours ici. La page de
 * consentement est une page du frontend : le test vérifie que le point
 * d'autorisation y renvoie, puis joue la page en appelant ce qu'elle appelle,
 * `GET|POST /api/oauth/consent`, avec la demande reçue sur la redirection.
 */
final class OAuthFlowTest extends WebTestCase
{
    use DrivesTheOAuthFlow;

    /** Les cinq tables du modèle de connexion, sans les comptes. */
    private const array MCP_CONNECTION_TABLES = ['mcp_refresh_token', 'mcp_access_token', 'mcp_authorization_code', 'mcp_connection', 'mcp_client'];

    protected function setUp(): void
    {
        $this->setUpOAuthFlow();
    }

    protected function tearDown(): void
    {
        $this->tearDownOAuthFlow();
        parent::tearDown();
    }

    public function testTheWholeFlowIssuesTokens(): void
    {
        $tokens = $this->wholeFlow();

        self::assertSame('Bearer', $tokens['first']['token_type']);
        self::assertNotSame($tokens['first']['access_token'], $tokens['refreshed']['access_token']);
        self::assertNotSame($tokens['first']['refresh_token'], $tokens['refreshed']['refresh_token']);

        // Le jeton de rafraîchissement remplacé est refusé (rotation, RFC 9700
        // §4.14.2) ; son successeur reste bon.
        $this->assertOAuthError($this->postForm($tokens['tokenPath'], [
            'grant_type' => 'refresh_token',
            'refresh_token' => $tokens['first']['refresh_token'],
            'client_id' => self::CLIENT_ID,
        ]), Response::HTTP_BAD_REQUEST, 'invalid_grant');
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM mcp_refresh_token WHERE revoked_at IS NULL'));
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM mcp_access_token WHERE revoked_at IS NULL'));

        // 6. Le client rend son jeton de rafraîchissement au point de
        //    révocation annoncé (RFC 7009) : lui et son jeton d'accès meurent,
        //    le rafraîchissement suivant est refusé.
        $revoked = $this->postForm($tokens['revocationPath'], ['token' => $tokens['refreshed']['refresh_token'], 'client_id' => self::CLIENT_ID]);
        self::assertSame(Response::HTTP_OK, $revoked->getStatusCode(), substr((string) $revoked->getContent(), 0, 500));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_refresh_token WHERE revoked_at IS NULL'));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_access_token WHERE revoked_at IS NULL'));
        $this->assertOAuthError($this->postForm($tokens['tokenPath'], [
            'grant_type' => 'refresh_token',
            'refresh_token' => $tokens['refreshed']['refresh_token'],
            'client_id' => self::CLIENT_ID,
        ]), Response::HTTP_BAD_REQUEST, 'invalid_grant');
    }

    /**
     * Ni le code, ni un jeton, ni l'identifiant d'un jeton n'atteint une
     * table : leurs empreintes seules. Les identifiants du code et des
     * rafraîchissements sont lus dans leurs charges chiffrées, sous la clé de
     * league ; celui d'un jeton d'accès opaque **est** le jeton.
     */
    public function testNoTokenIsStoredInClear(): void
    {
        $tokens = $this->wholeFlow();
        $first = $tokens['first'];
        $refreshed = $tokens['refreshed'];
        $code = $this->payloadOf($tokens['code']);
        $firstRefresh = $this->payloadOf($first['refresh_token']);
        $secondRefresh = $this->payloadOf($refreshed['refresh_token']);
        $codeIdentifier = $this->field($code, 'auth_code_id');
        $secondRefreshIdentifier = $this->field($secondRefresh, 'refresh_token_id');

        $secrets = [
            'code' => $tokens['code'],
            'identifiant du code' => $codeIdentifier,
            'premier jeton d\'accès' => $first['access_token'],
            'premier jeton de rafraîchissement' => $first['refresh_token'],
            'identifiant du premier rafraîchissement' => $this->field($firstRefresh, 'refresh_token_id'),
            'second jeton d\'accès' => $refreshed['access_token'],
            'second jeton de rafraîchissement' => $refreshed['refresh_token'],
            'identifiant du second rafraîchissement' => $secondRefreshIdentifier,
        ];
        // La charge d'un rafraîchissement nomme son jeton d'accès : le même,
        // déjà dans la liste.
        self::assertSame($first['access_token'], $this->field($firstRefresh, 'access_token_id'));
        self::assertSame($refreshed['access_token'], $this->field($secondRefresh, 'access_token_id'));

        $tables = $this->tablesAsJson();

        foreach ($secrets as $name => $secret) {
            self::assertNotSame('', $secret, $name);

            foreach ($tables as $table => $json) {
                self::assertStringNotContainsString($secret, $json, \sprintf('%s en clair dans %s', $name, $table));
            }
        }

        // La lecture porte bien sur les lignes du parcours : les empreintes y sont.
        self::assertStringContainsString(hash('sha256', $codeIdentifier), $tables['mcp_authorization_code']);
        self::assertStringContainsString(hash('sha256', $refreshed['access_token']), $tables['mcp_access_token']);
        self::assertStringContainsString(hash('sha256', $secondRefreshIdentifier), $tables['mcp_refresh_token']);
    }

    /**
     * Un `state` qui porte ce qu'un encodage maladroit casserait (`+`, `&`,
     * `=`, `%`, une espace, `#`, `/`, `?`, des caractères hors ASCII), et une
     * redirection `https` enregistrée **avec sa propre requête** : le client
     * reçoit son `state` à l'octet près, sa requête enregistrée intacte et en
     * tête, `code` et `state` ajoutés derrière par league, puis `iss` en
     * dernier (IssParameter), sans rien casser de ce qui précède.
     *
     * La page de consentement est du frontend, qu'un test PHPUnit ne fait pas
     * tourner : elle est jouée ici avec les valeurs exactes qu'elle enverrait.
     * Elle lit la requête reçue déjà décodée (`route.query` de vue-router :
     * `+` en espace, `%XX` décodé — ce que `parse_str()` fait de la requête
     * que le point d'autorisation construit par `http_build_query()`), la
     * renvoie telle quelle au `GET` du résumé, puis dans le corps JSON du
     * `POST`, en camelCase (`consentParamsFromQuery()`). Ce que la page fait
     * de ces valeurs est épinglé côté frontend (`consentParams.spec.ts`).
     */
    public function testAnAwkwardStateAndARegisteredQueryReachTheClientIntact(): void
    {
        $state = 'a+b c&d=e%zz%2B/?#é✓ fin';
        $redirectUri = 'https://client.example.test/cb?tenant=a';
        $clientId = 'https://queried-client.example.test/metadata.json';
        self::getContainer()->get(McpClientRepositoryInterface::class)->add(McpClient::register($clientId, 'Client à requête', [$redirectUri], new DateTimeImmutable()));
        $overrides = ['client_id' => $clientId, 'redirect_uri' => $redirectUri, 'state' => $state];

        // 1. Autorisation : la demande repart vers la page, `state` compris.
        $this->client->request('GET', self::AUTHORIZE_PATH.'?'.http_build_query($this->validParameters($overrides)));
        $redirect = $this->client->getResponse();
        self::assertSame(Response::HTTP_FOUND, $redirect->getStatusCode(), substr((string) $redirect->getContent(), 0, 500));
        parse_str((string) parse_url((string) $redirect->headers->get('Location'), \PHP_URL_QUERY), $received);
        self::assertSame($state, $received['state'] ?? null);
        self::assertSame($redirectUri, $received['redirect_uri'] ?? null);

        // 2. La page : le résumé lu avec la requête reçue, puis la décision
        //    renvoyée avec ces mêmes valeurs.
        $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);
        $this->client->request('GET', self::CONSENT_PATH.'?'.http_build_query($received));
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), substr((string) $this->client->getResponse()->getContent(), 0, 500));
        self::assertSame('client.example.test', $this->json($this->client->getResponse())['redirectHost'] ?? null);
        $this->postConsent(['approved' => true, ...$this->asDecisionBody($received)], $this->xsrfToken());
        $callback = $this->redirectUriOf($this->client->getResponse());

        // 3. Le retour au client : sa requête d'abord, intacte ; `iss` en dernier.
        self::assertStringStartsWith($redirectUri.'&', $callback);
        $issuer = $this->publicApiBase().'/api/oauth';
        self::assertStringEndsWith('&'.http_build_query(['iss' => $issuer]), $callback);
        $query = $this->queryOf($callback);
        self::assertSame(['tenant', 'code', 'state', 'iss'], array_keys($query));
        self::assertSame('a', $query['tenant']);
        self::assertSame($state, $query['state']);
        self::assertSame($issuer, $query['iss']);
        $code = $query['code'];
        self::assertIsString($code);

        // 4. L'échange, sous la même redirection, requête comprise.
        $tokens = $this->tokensOf($this->postForm(self::TOKEN_PATH, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $clientId,
            'code_verifier' => self::CODE_VERIFIER,
            'resource' => $this->resource(),
        ]));
        self::assertNotSame('', $tokens['access_token']);
    }

    /**
     * Le parcours d'un client MCP, de la découverte au premier
     * rafraîchissement.
     *
     * @return array{code: string, tokenPath: string, revocationPath: string, first: array{token_type: mixed, access_token: string, refresh_token: string}, refreshed: array{token_type: mixed, access_token: string, refresh_token: string}}
     */
    private function wholeFlow(): array
    {
        // 1. Découverte : la ressource nomme son serveur d'autorisation
        //    (RFC 9728), dont le document est au chemin bien connu inséré
        //    devant le chemin de l'émetteur (RFC 8414 §3.1).
        $resourceDocument = $this->getJson('/.well-known/oauth-protected-resource'.$this->pathOf($this->resource()));
        self::assertSame($this->resource(), $resourceDocument['resource'] ?? null);
        $servers = $resourceDocument['authorization_servers'] ?? null;
        self::assertIsArray($servers);
        self::assertCount(1, $servers);
        $issuer = $servers[0];
        self::assertIsString($issuer);
        $serverDocument = $this->getJson('/.well-known/oauth-authorization-server'.$this->pathOf($issuer));
        self::assertSame($issuer, $serverDocument['issuer'] ?? null);
        $authorizationPath = $this->pathOf($serverDocument['authorization_endpoint'] ?? null);
        $tokenPath = $this->pathOf($serverDocument['token_endpoint'] ?? null);
        $revocationPath = $this->pathOf($serverDocument['revocation_endpoint'] ?? null);

        // 2. Autorisation : la personne est renvoyée à la page de consentement.
        $this->client->request('GET', $authorizationPath.'?'.http_build_query($this->validParameters()), server: ['HTTP_ACCEPT_LANGUAGE' => 'fr']);
        $redirect = $this->client->getResponse();
        self::assertSame(Response::HTTP_FOUND, $redirect->getStatusCode(), substr((string) $redirect->getContent(), 0, 500));
        $location = (string) $redirect->headers->get('Location');
        // La page du frontend, dans la langue demandée ; sa base est celle du
        // frontend, pas de l'API.
        self::assertStringStartsWith(rtrim(self::getContainer()->getParameter('app.frontend_base_url'), '/').'/fr/oauth/consent?', $location);
        $consentQuery = (string) parse_url($location, \PHP_URL_QUERY);

        // 3. Consentement d'un compte de confiance : la page lit la demande,
        //    puis renvoie la décision ; le code part vers le client.
        $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);
        $this->client->request('GET', self::CONSENT_PATH.'?'.$consentQuery);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), substr((string) $this->client->getResponse()->getContent(), 0, 500));
        $callback = $this->redirectUriOf($this->decide(approved: true));
        self::assertStringStartsWith(self::LOOPBACK_REDIRECT.'?', $callback);
        $query = $this->queryOf($callback);
        self::assertSame('state-1', $query['state'] ?? null);
        self::assertSame($issuer, $query['iss'] ?? null);
        $code = $query['code'] ?? null;
        self::assertIsString($code);

        // 4. Échange du code, PKCE compris.
        $first = $this->tokensOf($this->postForm($tokenPath, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::LOOPBACK_REDIRECT,
            'client_id' => self::CLIENT_ID,
            'code_verifier' => self::CODE_VERIFIER,
            'resource' => $this->resource(),
        ]));

        // 5. Rafraîchissement : un nouveau couple.
        $refreshed = $this->tokensOf($this->postForm($tokenPath, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $first['refresh_token'],
            'client_id' => self::CLIENT_ID,
            'resource' => $this->resource(),
        ]));

        return ['code' => $code, 'tokenPath' => $tokenPath, 'revocationPath' => $revocationPath, 'first' => $first, 'refreshed' => $refreshed];
    }

    /**
     * @return array{token_type: mixed, access_token: string, refresh_token: string}
     */
    private function tokensOf(Response $response): array
    {
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        $this->assertNotStored($response);
        $body = $this->json($response);
        $accessToken = $body['access_token'] ?? null;
        $refreshToken = $body['refresh_token'] ?? null;
        self::assertIsString($accessToken);
        self::assertIsString($refreshToken);

        return ['token_type' => $body['token_type'] ?? null, 'access_token' => $accessToken, 'refresh_token' => $refreshToken];
    }

    /**
     * Un champ textuel d'une charge déchiffrée.
     *
     * @param array<string, mixed> $payload
     */
    private function field(array $payload, string $name): string
    {
        $value = $payload[$name] ?? null;
        self::assertIsString($value, $name);
        self::assertNotSame('', $value, $name);

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function getJson(string $path): array
    {
        $this->client->request('GET', $path);
        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $path);

        return $this->json($response);
    }

    /** Le chemin d'une URL annoncée, que le navigateur de test sert sous son hôte. */
    private function pathOf(mixed $url): string
    {
        self::assertIsString($url);
        $path = parse_url($url, \PHP_URL_PATH);
        self::assertIsString($path);

        return $path;
    }

    /**
     * Chaque ligne des cinq tables du modèle, encodée en JSON.
     *
     * @return array<string, string> table => ses lignes en JSON
     */
    private function tablesAsJson(): array
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $tables = [];

        foreach (self::MCP_CONNECTION_TABLES as $table) {
            $rows = $connection->fetchAllAssociative('SELECT * FROM '.$table);
            self::assertNotSame([], $rows, $table);
            $tables[$table] = json_encode($rows, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        }

        return $tables;
    }
}
