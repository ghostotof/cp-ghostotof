<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Presentation\Controller;

use App\Tests\Mcp\Connection\DrivesTheOAuthFlow;
use App\Tests\Support\ReadsSecurityAuditLog;
use App\Tests\Support\TestKeys;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le point de jeton OAuth de la connexion MCP (#647, spec 0007 phase 2),
 * `POST /api/oauth/token`, de bout en bout par HTTP : l'échange d'un code
 * contre un jeton d'accès et un jeton de rafraîchissement, et ses refus.
 *
 * - Un succès est la réponse de league (RFC 6749 §5.1) : `token_type`
 *   `Bearer`, `expires_in` d'une heure, les deux jetons, jamais en cache.
 * - Un refus est le JSON de RFC 6749 §5.2 (`error`), jamais un problem+json,
 *   jamais une redirection : les clients OAuth ne lisent que ce format.
 * - La ressource (RFC 8707) est facultative ici ; présente, elle doit être
 *   le serveur MCP du site, sinon `invalid_target`.
 * - Le firewall `oauth` ne lit aucun cookie, et la garde CSRF n'y touche pas :
 *   un client OAuth n'est pas un navigateur.
 *
 * Les courses connues de league (jetons émis avant la révocation du code)
 * sont l'objet de #649 ; l'échange séquentiel d'un code deux fois est refusé.
 */
final class TokenControllerTest extends WebTestCase
{
    use DrivesTheOAuthFlow;
    use ReadsSecurityAuditLog;

    protected function setUp(): void
    {
        $this->setUpOAuthFlow();
    }

    protected function tearDown(): void
    {
        $this->tearDownOAuthFlow();
        parent::tearDown();
    }

    /**
     * Le code contre un jeton d'accès opaque d'une heure et un jeton de
     * rafraîchissement ; en base, une ligne de chacun, le code révoqué.
     */
    public function testACodeIsExchangedForAnAccessTokenAndARefreshToken(): void
    {
        $response = $this->exchange($this->grantedCode());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        self::assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertNotStored($response);
        $body = $this->json($response);
        self::assertEqualsCanonicalizing(['token_type', 'expires_in', 'access_token', 'refresh_token'], array_keys($body));
        self::assertSame('Bearer', $body['token_type']);
        // league calcule l'échéance moins l'instant de la réponse : une seconde
        // peut s'être écoulée entre l'émission et la réponse.
        self::assertContains($body['expires_in'], [3599, 3600]);
        self::assertIsString($body['access_token']);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{80}\z/', $body['access_token']);
        self::assertIsString($body['refresh_token']);
        self::assertNotSame('', $body['refresh_token']);

        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM mcp_access_token WHERE revoked_at IS NULL'));
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM mcp_refresh_token WHERE revoked_at IS NULL'));
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM mcp_authorization_code WHERE revoked_at IS NOT NULL'));
    }

    /** RFC 8707 : la ressource est facultative au point de jeton. */
    public function testTheResourceMayBeOmitted(): void
    {
        $response = $this->exchange($this->grantedCode(), ['resource' => null]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        self::assertArrayHasKey('access_token', $this->json($response));
    }

    /** Une ressource étrangère : `invalid_target`, aucun jeton émis. */
    public function testAForeignResourceIsInvalidTarget(): void
    {
        $response = $this->exchange($this->grantedCode(), ['resource' => 'https://other.example.test/api/mcp']);

        $this->assertOAuthError($response, Response::HTTP_BAD_REQUEST, 'invalid_target');
        $this->assertNoTokenIssued();
    }

    /** PKCE (RFC 7636 §4.6) : un vérificateur bien formé mais faux. */
    public function testAWrongCodeVerifierIsInvalidGrant(): void
    {
        $response = $this->exchange($this->grantedCode(), ['code_verifier' => str_repeat('a', 43)]);

        $this->assertOAuthError($response, Response::HTTP_BAD_REQUEST, 'invalid_grant');
        $this->assertNoTokenIssued();
    }

    /**
     * PKCE : sans vérificateur, un paramètre requis manque — `invalid_request`,
     * la réponse de league (OAuth 2.1 §3.2.4), et aucun jeton.
     */
    public function testAMissingCodeVerifierIsInvalidRequest(): void
    {
        $response = $this->exchange($this->grantedCode(), ['code_verifier' => null]);

        $this->assertOAuthError($response, Response::HTTP_BAD_REQUEST, 'invalid_request');
        $this->assertNoTokenIssued();
    }

    /** Un code ne s'échange qu'une fois. */
    public function testACodeExchangedTwiceIsRefusedTheSecondTime(): void
    {
        $code = $this->grantedCode();
        self::assertSame(Response::HTTP_OK, $this->exchange($code)->getStatusCode());

        $this->assertOAuthError($this->exchange($code), Response::HTTP_BAD_REQUEST, 'invalid_grant');
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM mcp_access_token'));
    }

    /**
     * La redirection de l'échange doit être celle de l'autorisation
     * (RFC 6749 §4.1.3).
     *
     * @return iterable<string, array{string|null}>
     */
    public static function redirectUrisOtherThanTheAuthorizedOne(): iterable
    {
        yield 'autre port' => ['http://localhost:49153/callback'];
        yield 'autre redirection enregistrée' => ['https://client.example.test/callback'];
        yield 'absente' => [null];
    }

    #[DataProvider('redirectUrisOtherThanTheAuthorizedOne')]
    public function testARedirectUriOtherThanTheAuthorizedOneIsInvalidRequest(?string $redirectUri): void
    {
        $response = $this->exchange($this->grantedCode(), ['redirect_uri' => $redirectUri]);

        $this->assertOAuthError($response, Response::HTTP_BAD_REQUEST, 'invalid_request');
        $this->assertNoTokenIssued();
    }

    /**
     * Un `client_secret` qu'un client public n'a pas à envoyer est **ignoré**,
     * pas refusé : league 9.4.1 ne consulte `ClientRepository::validateClient()`
     * que pour un client confidentiel, et aucun ne l'est. Dans le corps comme
     * en HTTP Basic (que league lit aussi), à l'échange comme au
     * rafraîchissement, la réponse est celle d'une requête sans secret. Si
     * une montée de league se mettait à le refuser, ce test le dirait — un
     * client qui l'envoie par habitude serait alors coupé. La valeur est tirée
     * à l'exécution (TestKeys), jamais un littéral que GitGuardian lirait
     * comme un secret en dur.
     */
    public function testASuperfluousClientSecretIsIgnored(): void
    {
        $superfluous = TestKeys::material('superfluous-client');
        $exchanged = $this->exchange($this->grantedCode(), ['client_secret' => $superfluous]);
        self::assertSame(Response::HTTP_OK, $exchanged->getStatusCode(), substr((string) $exchanged->getContent(), 0, 500));
        $refreshToken = $this->json($exchanged)['refresh_token'] ?? null;
        self::assertIsString($refreshToken);

        $refreshed = $this->postForm(self::TOKEN_PATH, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => self::CLIENT_ID,
        ], ['PHP_AUTH_USER' => self::CLIENT_ID, 'PHP_AUTH_PW' => $superfluous]);
        self::assertSame(Response::HTTP_OK, $refreshed->getStatusCode(), substr((string) $refreshed->getContent(), 0, 500));
        self::assertArrayHasKey('access_token', $this->json($refreshed));
    }

    /** Un client inconnu : `invalid_client`, 401. */
    public function testAnUnknownClientIsInvalidClient(): void
    {
        $response = $this->exchange($this->grantedCode(), ['client_id' => 'https://unknown.example.test/metadata.json']);

        $this->assertOAuthError($response, Response::HTTP_UNAUTHORIZED, 'invalid_client');
        $this->assertNoTokenIssued();
    }

    /** Le code d'un client présenté par un autre client enregistré. */
    public function testACodeIssuedToAnotherClientIsRefused(): void
    {
        $response = $this->exchange($this->grantedCode(), ['client_id' => self::OTHER_CLIENT_ID]);

        $this->assertOAuthError($response, Response::HTTP_BAD_REQUEST, 'invalid_request');
        $this->assertNoTokenIssued();
    }

    /**
     * Un jeton de rafraîchissement présenté par un autre client que celui à
     * qui il a été émis : `invalid_grant` (league le lit dans la charge
     * chiffrée), aucun nouveau jeton.
     */
    public function testARefreshTokenIssuedToAnotherClientIsRefused(): void
    {
        $issued = $this->json($this->exchange($this->grantedCode()));
        self::assertIsString($issued['refresh_token'] ?? null);

        $response = $this->refresh($issued['refresh_token'], ['client_id' => self::OTHER_CLIENT_ID]);

        $this->assertOAuthError($response, Response::HTTP_BAD_REQUEST, 'invalid_grant');
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM mcp_access_token'));
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM mcp_refresh_token'));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_refresh_token WHERE revoked_at IS NOT NULL'));
    }

    /**
     * Un autre corps qu'un formulaire (RFC 6749 §4.1.3) : `invalid_request`
     * au format de RFC 6749, aucun jeton. Le pont PSR-7 analyse pourtant un
     * corps JSON en paramètres, que league aurait servis (200 avant le
     * refus, mesuré).
     *
     * @return iterable<string, array{string}>
     */
    public static function bodiesOtherThanAForm(): iterable
    {
        yield 'JSON' => ['application/json'];
        yield 'texte' => ['text/plain'];
    }

    #[DataProvider('bodiesOtherThanAForm')]
    public function testABodyOtherThanAFormIsInvalidRequest(string $contentType): void
    {
        $code = $this->grantedCode();

        $this->client->request('POST', self::TOKEN_PATH, server: ['CONTENT_TYPE' => $contentType, 'HTTP_ACCEPT' => 'application/json'], content: self::jsonBody([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::LOOPBACK_REDIRECT,
            'client_id' => self::CLIENT_ID,
            'code_verifier' => self::CODE_VERIFIER,
        ]));

        $this->assertOAuthError($this->client->getResponse(), Response::HTTP_BAD_REQUEST, 'invalid_request');
        $this->assertNoTokenIssued();
    }

    /** Le type de contenu se lit sans ses paramètres ni sa casse. */
    public function testAFormWithACharsetIsServed(): void
    {
        $code = $this->grantedCode();

        $response = $this->postForm(self::TOKEN_PATH, http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::LOOPBACK_REDIRECT,
            'client_id' => self::CLIENT_ID,
            'code_verifier' => self::CODE_VERIFIER,
        ]), ['CONTENT_TYPE' => 'Application/X-WWW-Form-Urlencoded; charset=UTF-8']);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
    }

    /**
     * Une autre méthode que POST : le 405 du routeur, avant le contrôleur. Il
     * sort au format commun des erreurs sous `/api` (ApiJsonErrorFormatListener,
     * un problem+json), pas au format OAuth — accepté : aucun client OAuth
     * n'appelle ce point autrement qu'en POST ; l'essentiel est qu'il ne
     * sorte jamais en page HTML.
     */
    public function testAGetIsA405InJson(): void
    {
        $this->client->request('GET', self::TOKEN_PATH);

        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $response->getStatusCode());
        self::assertStringContainsString('json', (string) $response->headers->get('Content-Type'));
        self::assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $this->json($response)['status'] ?? null);
    }

    /** Un grant que le serveur ne sert pas. */
    public function testAnUnsupportedGrantIsRefused(): void
    {
        $response = $this->postForm(self::TOKEN_PATH, ['grant_type' => 'client_credentials', 'client_id' => self::CLIENT_ID]);

        $this->assertOAuthError($response, Response::HTTP_BAD_REQUEST, 'unsupported_grant_type');
    }

    /**
     * RFC 6749 §3.2 : un paramètre ne figure qu'une fois. PHP garderait la
     * dernière occurrence, un autre analyseur la première ; la requête est
     * refusée, jamais lue d'une façon ou de l'autre.
     *
     * @return iterable<string, array{string}>
     */
    public static function repeatableParameters(): iterable
    {
        foreach (['grant_type', 'code', 'redirect_uri', 'client_id', 'code_verifier', 'resource'] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('repeatableParameters')]
    public function testARepeatedParameterIsInvalidRequest(string $name): void
    {
        $parameters = [
            'grant_type' => 'authorization_code',
            'code' => $this->grantedCode(),
            'redirect_uri' => self::LOOPBACK_REDIRECT,
            'client_id' => self::CLIENT_ID,
            'code_verifier' => self::CODE_VERIFIER,
            'resource' => $this->resource(),
        ];
        // La valeur répétée est la même : seule la répétition est refusée.
        $body = http_build_query($parameters).'&'.http_build_query([$name => $parameters[$name]]);

        $response = $this->postForm(self::TOKEN_PATH, $body);

        $this->assertOAuthError($response, Response::HTTP_BAD_REQUEST, 'invalid_request');
        $this->assertNoTokenIssued();
    }

    /**
     * Le point est exclu de la garde CSRF, ce qui n'est sûr que parce que son
     * firewall ne lit aucun cookie : le `BEARER` d'une personne de confiance,
     * sans en-tête XSRF, n'y vaut ni un 403 ni une identité — la même réponse
     * qu'à un appelant sans cookie.
     */
    public function testABearerCookieWithoutTheXsrfHeaderIsNeitherRefusedNorRead(): void
    {
        $code = $this->grantedCode();
        self::assertNotNull($this->client->getCookieJar()->get('BEARER'));

        $refusedWithCookie = $this->exchange($code, ['client_id' => 'https://unknown.example.test/metadata.json']);
        $this->assertOAuthError($refusedWithCookie, Response::HTTP_UNAUTHORIZED, 'invalid_client');
        self::assertSame([], self::securityAuditEvents('csrf-rejected'));
        self::assertNull(self::getContainer()->get('security.token_storage')->getToken());

        $issued = $this->exchange($code);
        self::assertSame(Response::HTTP_OK, $issued->getStatusCode(), substr((string) $issued->getContent(), 0, 500));
        self::assertSame([], self::securityAuditEvents('csrf-rejected'));
        self::assertNull(self::getContainer()->get('security.token_storage')->getToken());

        $this->client->getCookieJar()->clear();
        $refusedWithoutCookie = $this->exchange($code, ['client_id' => 'https://unknown.example.test/metadata.json']);
        self::assertSame($refusedWithCookie->getStatusCode(), $refusedWithoutCookie->getStatusCode());
        self::assertSame($refusedWithCookie->getContent(), $refusedWithoutCookie->getContent());
    }

    private function assertNoTokenIssued(): void
    {
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_access_token'));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_refresh_token'));
    }
}
