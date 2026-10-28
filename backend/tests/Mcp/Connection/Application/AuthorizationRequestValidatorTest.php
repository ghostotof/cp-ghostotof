<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Application;

use App\Mcp\Connection\Application\AuthorizationRequestValidatorInterface;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Tests\Mcp\Connection\RegisteredMcpClient;
use App\Tests\Support\TestDatabase;
use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le validateur de la demande d'autorisation (#647) tel que le conteneur le
 * construit : le résumé qu'il rend à la page de consentement (tâche 6), et
 * l'ordre de ses refus — ce qui est refusé sans redirection l'est avant
 * league, ce qui l'est avec redirection l'est après que league a validé le
 * client et sa redirection. Le parcours HTTP est celui
 * d'AuthorizeControllerTest.
 */
final class AuthorizationRequestValidatorTest extends KernelTestCase
{
    private const string CLIENT_ID = 'https://client.example.test/metadata.json';

    private const string CODE_CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    protected function setUp(): void
    {
        self::bootKernel();
        $this->purge();
        self::getContainer()->get(McpClientRepositoryInterface::class)->add(
            RegisteredMcpClient::of(self::CLIENT_ID, 'Client de test', ['http://localhost/callback', 'https://client.example.test/callback']),
        );
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function redirects(): iterable
    {
        yield 'boucle locale, port éphémère' => ['http://localhost:49152/callback', 'localhost'];
        yield 'https' => ['https://client.example.test/callback', 'client.example.test'];
    }

    #[DataProvider('redirects')]
    public function testAValidRequestIsSummarisedForTheConsentPage(string $redirectUri, string $host): void
    {
        $summary = $this->validator()->validate($this->request(['redirect_uri' => $redirectUri]));

        self::assertSame(self::CLIENT_ID, $summary->clientId);
        self::assertSame('Client de test', $summary->clientName);
        self::assertSame($redirectUri, $summary->redirectUri);
        self::assertSame($host, $summary->redirectHost);
        self::assertSame('nominative:read', $summary->scope);
        self::assertSame($this->resource(), $summary->resource);
        self::assertSame($redirectUri, $summary->authorizationRequest->getRedirectUri());
        self::assertSame('state-1', $summary->authorizationRequest->getState());
        self::assertSame(self::CODE_CHALLENGE, $summary->authorizationRequest->getCodeChallenge());
    }

    /**
     * league rogne la redirection avant de la valider : le résumé porte la
     * forme qu'il a retenue, jamais la brute (un saut de ligne final, lu par
     * League\Uri, laissait une redirection sans hôte : un 500).
     *
     * @return iterable<string, array{string}>
     */
    public static function paddedRedirects(): iterable
    {
        yield 'espace final' => ['http://localhost:49152/callback '];
        yield 'saut de ligne final' => ["http://localhost:49152/callback\n"];
        yield 'espace de tête' => [' http://localhost:49152/callback'];
    }

    #[DataProvider('paddedRedirects')]
    public function testTheSummaryCarriesTheRedirectLeagueRetained(string $redirectUri): void
    {
        $summary = $this->validator()->validate($this->request(['redirect_uri' => $redirectUri]));

        self::assertSame('http://localhost:49152/callback', $summary->redirectUri);
        self::assertSame($summary->authorizationRequest->getRedirectUri(), $summary->redirectUri);
        self::assertSame('localhost', $summary->redirectHost);
        self::assertSame('http://localhost:49152/callback', $summary->consentQuery()['redirect_uri']);
    }

    #[DataProvider('paddedRedirects')]
    public function testAnInvalidTargetGoesToTheRedirectLeagueRetained(string $redirectUri): void
    {
        try {
            $this->validator()->validate($this->request(['redirect_uri' => $redirectUri, 'resource' => 'https://other.example.test/api/mcp']));
            self::fail('La ressource aurait dû être refusée.');
        } catch (OAuthServerException $exception) {
            self::assertSame('http://localhost:49152/callback?state=state-1', $exception->getRedirectUri());
        }
    }

    /**
     * Les paramètres transmis à la page de consentement : les validés, dans
     * l'ordre fixe, le scope par défaut écrit.
     */
    public function testTheConsentQueryHoldsTheValidatedParametersOnly(): void
    {
        $summary = $this->validator()->validate($this->request(['scope' => null]));

        self::assertSame([
            'response_type' => 'code',
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => 'http://localhost:49152/callback',
            'scope' => 'nominative:read',
            'state' => 'state-1',
            'code_challenge' => self::CODE_CHALLENGE,
            'code_challenge_method' => 'S256',
            'resource' => $this->resource(),
        ], $summary->consentQuery());
    }

    /**
     * La pollution se lit sur la requête brute (l'URI), que les paramètres
     * analysés par PHP ont déjà effacée.
     */
    public function testARepeatedParameterInTheRawQueryIsRefused(): void
    {
        $parameters = array_filter($this->request()->getQueryParams(), is_string(...));
        $raw = http_build_query($parameters).'&client_id=https%3A%2F%2Fother.example.test%2Fm.json';
        parse_str($raw, $parsed);

        try {
            $this->validator()->validate(new ServerRequest('GET', 'http://localhost/api/oauth/authorize?'.$raw)->withQueryParams($parsed));
            self::fail('La répétition aurait dû être refusée.');
        } catch (OAuthServerException $exception) {
            self::assertSame('invalid_request', $exception->getErrorType());
            self::assertFalse($exception->hasRedirect());
        }
    }

    /**
     * Sans `scope`, league retient le scope par défaut : le résumé le nomme.
     */
    public function testAMissingScopeIsSummarisedAsTheDefaultOne(): void
    {
        $summary = $this->validator()->validate($this->request(['scope' => null]));

        self::assertSame('nominative:read', $summary->scope);
    }

    /**
     * Une ressource refusée est renvoyée au client : la redirection est celle,
     * demandée, que league a validée, `state` compris.
     */
    public function testAForeignResourceIsRefusedOnTheValidatedRedirect(): void
    {
        try {
            $this->validator()->validate($this->request(['resource' => 'https://other.example.test/api/mcp']));
            self::fail('La ressource aurait dû être refusée.');
        } catch (OAuthServerException $exception) {
            self::assertSame('invalid_target', $exception->getErrorType());
            self::assertSame(400, $exception->getHttpStatusCode());
            self::assertSame('http://localhost:49152/callback?state=state-1', $exception->getRedirectUri());
        }
    }

    /**
     * Les refus d'avant league ne portent jamais de redirection.
     *
     * @return iterable<string, array{array<string, string|list<string>|null>}>
     */
    public static function refusedBeforeLeague(): iterable
    {
        yield 'redirection absente' => [['redirect_uri' => null]];
        yield 'redirection vide' => [['redirect_uri' => '']];
        yield 'redirection blanche' => [['redirect_uri' => " \n"]];
        yield 'client absent' => [['client_id' => null]];
        yield 'client blanc' => [['client_id' => ' ']];
        yield 'client_id en tableau' => [['client_id' => [self::CLIENT_ID]]];
        yield 'state trop long' => [['state' => str_repeat('s', 1025)]];
    }

    /**
     * @param array<string, string|list<string>|null> $overrides
     */
    #[DataProvider('refusedBeforeLeague')]
    public function testARefusalBeforeLeagueNeverRedirects(array $overrides): void
    {
        try {
            $this->validator()->validate($this->request($overrides));
            self::fail('La demande aurait dû être refusée.');
        } catch (OAuthServerException $exception) {
            self::assertSame('invalid_request', $exception->getErrorType());
            self::assertFalse($exception->hasRedirect());
        }
    }

    private function validator(): AuthorizationRequestValidatorInterface
    {
        return self::getContainer()->get(AuthorizationRequestValidatorInterface::class);
    }

    /**
     * @param array<string, string|list<string>|null> $overrides
     */
    private function request(array $overrides = []): ServerRequest
    {
        $parameters = array_filter([
            'response_type' => 'code',
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => 'http://localhost:49152/callback',
            'scope' => 'nominative:read',
            'state' => 'state-1',
            'code_challenge' => self::CODE_CHALLENGE,
            'code_challenge_method' => 'S256',
            'resource' => $this->resource(),
            ...$overrides,
        ], static fn (string|array|null $value): bool => null !== $value);

        return new ServerRequest('GET', 'http://localhost/api/oauth/authorize')->withQueryParams($parameters);
    }

    private function resource(): string
    {
        $base = self::getContainer()->getParameter('app.public_api_base_url');
        self::assertIsString($base);

        return rtrim($base, '/').'/api/mcp';
    }

    private function purge(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        TestDatabase::assertSafeToWipe($em->getConnection());
        foreach (['mcp_refresh_token', 'mcp_access_token', 'mcp_authorization_code', 'mcp_connection', 'mcp_client'] as $table) {
            $em->getConnection()->executeStatement('DELETE FROM '.$table);
        }
        $em->clear();
    }
}
