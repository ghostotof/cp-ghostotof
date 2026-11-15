<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Application;

use App\Mcp\Connection\Application\AuthorizationRequestValidator;
use App\Mcp\Connection\Application\AuthorizationRequestValidatorInterface;
use App\Mcp\Connection\Application\Cimd\CimdClientAdmissionInterface;
use App\Mcp\Connection\Application\OAuthEndpoints;
use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Exception\ClientMetadataRefusedException;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\CimdClientId;
use App\Mcp\Connection\Domain\ValueObject\ClientMetadataDocument;
use App\Mcp\Connection\Domain\ValueObject\ClientMetadataRefusal;
use App\Mcp\Connection\Domain\ValueObject\ClientRegistration;
use App\Tests\Mcp\Connection\Fixtures\ClaudeCodeMetadata;
use App\Tests\Mcp\Connection\RegisteredMcpClient;
use App\Tests\Support\ReadsSecurityAuditLog;
use App\Tests\Support\TestDatabase;
use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use LogicException;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

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
    use ReadsSecurityAuditLog;

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
        // league rogne le paramètre et servirait la ligne en base, sans que
        // l'admission (qui lit la valeur brute) ait rien jugé : refusé, jamais
        // corrigé (comme au point de jeton, #725). La redirection, elle, reste
        // acceptée rognée (paddedRedirects()).
        yield 'client précédé d\'une espace' => [['client_id' => ' '.self::CLIENT_ID]];
        yield 'client suivi d\'une espace' => [['client_id' => self::CLIENT_ID.' ']];
        yield 'client suivi d\'un saut de ligne' => [['client_id' => self::CLIENT_ID."\n"]];
        yield 'client déclaré précédé d\'une espace' => [['client_id' => ' '.ClaudeCodeMetadata::CLIENT_ID]];
        // Les autres blancs que trim() rogne : tabulation, retour chariot, NUL
        // et tabulation verticale, en tête et en queue.
        foreach (["\t" => 'une tabulation', "\r" => 'un retour chariot', "\0" => 'un octet nul', "\x0B" => 'une tabulation verticale'] as $blank => $name) {
            yield 'client précédé d\''.$name => [['client_id' => $blank.self::CLIENT_ID]];
            yield 'client suivi d\''.$name => [['client_id' => self::CLIENT_ID.$blank]];
        }
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

    /**
     * Un identifiant que l'admission ne prend pas en charge (`null`) est jugé
     * par les lignes en base, comme avant #651 : l'admission est interrogée
     * une fois, sur le `client_id` reçu, et le résumé reste « pré-enregistré ».
     */
    public function testAnIdentifierTheAdmissionLeavesToTheRowsIsJudgedByTheRows(): void
    {
        $admission = $this->createMock(CimdClientAdmissionInterface::class);
        $admission->expects(self::once())->method('admit')->with(self::CLIENT_ID)->willReturn(null);

        $summary = $this->validatorWith($admission)->validate($this->request());

        self::assertSame(self::CLIENT_ID, $summary->clientId);
        self::assertNull($summary->judgedDiscoveredClient());
        self::assertSame(ClientRegistration::Preregistered, $summary->registration());
        self::assertNull($summary->verifiedDomain(), 'Un client pré-enregistré n\'a pas de domaine vérifié.');
    }

    /**
     * Un client déclaré (CIMD) n'a pas de ligne : league le juge par celui que
     * l'admission dépose dans la requête, et le résumé nomme le domaine
     * du `client_id`, que la page de consentement affichera.
     */
    public function testADiscoveredClientIsJudgedByLeagueAndSummarisedAsCimd(): void
    {
        $client = $this->discoveredClient();
        $admission = $this->createMock(CimdClientAdmissionInterface::class);
        $admission->expects(self::once())->method('admit')->with(ClaudeCodeMetadata::CLIENT_ID)->willReturn($client);

        $summary = $this->validatorWith($admission)->validate($this->request(['client_id' => ClaudeCodeMetadata::CLIENT_ID]));

        self::assertSame(ClaudeCodeMetadata::CLIENT_ID, $summary->clientId);
        self::assertSame($client->getName(), $summary->clientName);
        self::assertSame($client, $summary->judgedDiscoveredClient());
        self::assertSame(ClientRegistration::Cimd, $summary->registration());
        self::assertSame('claude.ai', $summary->verifiedDomain());
        self::assertNull(
            self::getContainer()->get(McpClientRepositoryInterface::class)->findOneByClientId(ClaudeCodeMetadata::CLIENT_ID),
            'Le client déclaré n\'est pas persisté par l\'admission.',
        );
    }

    /**
     * Une ligne `cimd` laissée par une autorisation antérieure n'est jamais
     * servie au point d'autorisation sans que l'admission ait déposé le client
     * déclaré : le grant refuse `invalid_client`, sans redirection, quel que
     * soit le chemin qui a mené league à cette ligne. Deux déclencheurs réels :
     * une divergence entre le `client_id` jugé par l'admission et celui que lit
     * league, ou une ligne `cimd` dont l'identifiant n'a plus la forme CIMD
     * (`admit()` rend `null`). Un domaine retiré de la liste n'y parvient pas :
     * `admit()` lève `DomainNotAllowed` avant league (D-G).
     *
     * Le cas « `admit()` rend `null` » y est simulé par un double : {@see \App\Tests\Mcp\Connection\CimdAuthorizationTest::testAStoredCimdRowOutsideTheCimdFormIsRefusedAndAuditedAsUnreadable()} le joue de bout en bout, avec la vraie admission et une vraie ligne.
     */
    public function testAStoredCimdRowTheAdmissionDidNotVouchForIsNeverServed(): void
    {
        self::getContainer()->get(McpClientRepositoryInterface::class)->saveDiscovered($this->discoveredClient());
        $admission = self::createStub(CimdClientAdmissionInterface::class);
        $admission->method('admit')->willReturn(null);

        try {
            $this->validatorWith($admission)->validate($this->request(['client_id' => ClaudeCodeMetadata::CLIENT_ID, 'redirect_uri' => 'http://localhost:53124/callback']));
            self::fail('La ligne périmée aurait dû être refusée.');
        } catch (OAuthServerException $exception) {
            self::assertSame('invalid_client', $exception->getErrorType());
            self::assertFalse($exception->hasRedirect());
        }
    }

    /**
     * Le témoin de l'admission, pas celui du garde : la même ligne `cimd` en
     * base, mais l'admission rend cette fois le client et league le juge
     * lui, sans lire la ligne — la branche de getClientEntityOrFail() qui porte
     * le garde n'est donc pas parcourue (mesuré : un garde qui refuserait
     * toujours laisse ce test vert). Il prouve que le refus du test précédent
     * tient à l'absence d'admission. Le témoin positif du garde est dans
     * CimdAuthorizationTest::testACimdClientExchangesItsCodeAtTheTokenEndpointFromItsStoredRow :
     * le point d'accès des jetons lit la ligne `cimd`, et un garde qui ne
     * distinguerait pas le point d'autorisation le refuserait.
     */
    public function testTheSameStoredCimdRowIsServedWhenTheAdmissionVouchesForIt(): void
    {
        self::getContainer()->get(McpClientRepositoryInterface::class)->saveDiscovered($this->discoveredClient());
        $admission = self::createStub(CimdClientAdmissionInterface::class);
        $admission->method('admit')->willReturn($this->discoveredClient());

        $summary = $this->validatorWith($admission)->validate($this->request(['client_id' => ClaudeCodeMetadata::CLIENT_ID, 'redirect_uri' => 'http://localhost:53124/callback']));

        self::assertSame(ClaudeCodeMetadata::CLIENT_ID, $summary->clientId);
        self::assertSame([], self::securityAuditEvents('mcp-client-row-not-admitted'));
    }

    /**
     * La voie du refus PKCE (pas de `code_challenge`) relit le client avant de
     * choisir l'URI d'erreur (validatedErrorRedirectUri(), au point
     * d'autorisation) : une ligne `cimd` non admise y est refusée comme
     * ailleurs, `invalid_client` sans redirection — jamais un `invalid_request`
     * redirigé vers une redirection que rien n'a relue. L'audit
     * `mcp-client-row-not-admitted` est écrit une seule fois, avec l'hôte
     * seul : c'est l'unique ligne d'audit, celle du garde.
     *
     * Le cas « `admit()` rend `null` » y est simulé par un double : {@see \App\Tests\Mcp\Connection\CimdAuthorizationTest::testAStoredCimdRowOutsideTheCimdFormIsRefusedAndAuditedAsUnreadable()} le joue de bout en bout, avec la vraie admission et une vraie ligne.
     */
    public function testAStaleCimdRowIsRefusedWithoutRedirectOnThePkceRefusalPathToo(): void
    {
        self::getContainer()->get(McpClientRepositoryInterface::class)->saveDiscovered($this->discoveredClient());
        $admission = self::createStub(CimdClientAdmissionInterface::class);
        $admission->method('admit')->willReturn(null);

        try {
            $this->validatorWith($admission)->validate($this->request([
                'client_id' => ClaudeCodeMetadata::CLIENT_ID,
                'redirect_uri' => 'http://localhost:53124/callback',
                'code_challenge' => null,
            ]));
            self::fail('La ligne périmée aurait dû être refusée.');
        } catch (OAuthServerException $exception) {
            self::assertSame('invalid_client', $exception->getErrorType());
            self::assertFalse($exception->hasRedirect());
        }

        self::assertSame('claude.ai', self::singleSecurityAuditEvent('mcp-client-row-not-admitted')['clientHost']);
        self::assertCount(1, self::securityAuditRecords(), 'Une seule ligne d\'audit, celle du garde.');
    }

    /**
     * Le refus du garde est tracé : l'état qu'il signale (une ligne `cimd`
     * atteinte sans admission) relève de la défense en profondeur. L'hôte seul,
     * jamais le `client_id` brut.
     *
     * Le cas « `admit()` rend `null` » y est simulé par un double : {@see \App\Tests\Mcp\Connection\CimdAuthorizationTest::testAStoredCimdRowOutsideTheCimdFormIsRefusedAndAuditedAsUnreadable()} le joue de bout en bout, avec la vraie admission et une vraie ligne.
     */
    public function testTheRefusalOfAStoredCimdRowIsAudited(): void
    {
        self::getContainer()->get(McpClientRepositoryInterface::class)->saveDiscovered($this->discoveredClient());
        $admission = self::createStub(CimdClientAdmissionInterface::class);
        $admission->method('admit')->willReturn(null);

        try {
            $this->validatorWith($admission)->validate($this->request(['client_id' => ClaudeCodeMetadata::CLIENT_ID, 'redirect_uri' => 'http://localhost:53124/callback']));
            self::fail('La ligne périmée aurait dû être refusée.');
        } catch (OAuthServerException) {
        }

        $audit = self::singleSecurityAuditEvent('mcp-client-row-not-admitted');
        self::assertSame('claude.ai', $audit['clientHost']);
        self::assertStringNotContainsString(ClaudeCodeMetadata::CLIENT_ID, json_encode($audit, \JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('clientId', $audit);
    }

    /**
     * La redirection d'un client déclaré est jugée par son document :
     * une redirection qu'il ne déclare pas reste un `invalid_client` sans
     * redirection.
     */
    public function testADiscoveredClientIsHeldToTheRedirectsOfItsDocument(): void
    {
        $admission = self::createStub(CimdClientAdmissionInterface::class);
        $admission->method('admit')->willReturn($this->discoveredClient());

        try {
            $this->validatorWith($admission)->validate($this->request(['client_id' => ClaudeCodeMetadata::CLIENT_ID, 'redirect_uri' => 'https://evil.test/callback']));
            self::fail('La demande aurait dû être refusée.');
        } catch (OAuthServerException $exception) {
            self::assertSame('invalid_client', $exception->getErrorType());
            self::assertFalse($exception->hasRedirect());
        }
    }

    /**
     * @return iterable<string, array{ClientMetadataRefusal}>
     */
    public static function definitiveRefusals(): iterable
    {
        foreach (ClientMetadataRefusal::cases() as $refusal) {
            if (!$refusal->isTransient()) {
                yield $refusal->value => [$refusal];
            }
        }
    }

    /**
     * Une cause définitive est un client inconnu : `invalid_client`, 401, sans
     * redirection (aucune URI n'est validée), la cause n'étant pas rendue.
     */
    #[DataProvider('definitiveRefusals')]
    public function testADefinitiveRefusalIsAnUnknownClientWithoutRedirect(ClientMetadataRefusal $refusal): void
    {
        $exception = $this->refusedBy(ClientMetadataRefusedException::because($refusal));

        self::assertSame('invalid_client', $exception->getErrorType());
        self::assertSame(401, $exception->getHttpStatusCode());
        self::assertFalse($exception->hasRedirect());
    }

    /**
     * Le quota épuisé : 429 `temporarily_unavailable`, sans redirection ; le
     * refus d'origine reste chaîné (`previous`) pour que l'en-tête
     * `Retry-After` en soit tiré plus haut.
     */
    public function testAnExhaustedQuotaIsA429WithoutRedirect(): void
    {
        $original = ClientMetadataRefusedException::because(ClientMetadataRefusal::Throttled);

        $exception = $this->refusedBy($original);

        self::assertSame('temporarily_unavailable', $exception->getErrorType());
        self::assertSame(429, $exception->getHttpStatusCode());
        self::assertFalse($exception->hasRedirect());
        self::assertSame($original, $exception->getPrevious());
    }

    /**
     * @return iterable<string, array{ClientMetadataRefusal}>
     */
    public static function transientRefusalsOtherThanTheQuota(): iterable
    {
        foreach (ClientMetadataRefusal::cases() as $refusal) {
            if ($refusal->isTransient() && ClientMetadataRefusal::Throttled !== $refusal) {
                yield $refusal->value => [$refusal];
            }
        }
    }

    /**
     * Une cause passagère de l'hôte ou du réseau : 503 `temporarily_unavailable`,
     * sans redirection, le client peut réessayer.
     */
    #[DataProvider('transientRefusalsOtherThanTheQuota')]
    public function testATransientRefusalIsA503WithoutRedirect(ClientMetadataRefusal $refusal): void
    {
        $original = ClientMetadataRefusedException::because($refusal);

        $exception = $this->refusedBy($original);

        self::assertSame('temporarily_unavailable', $exception->getErrorType());
        self::assertSame(503, $exception->getHttpStatusCode());
        self::assertFalse($exception->hasRedirect());
        self::assertSame($original, $exception->getPrevious());
    }

    /**
     * La demande mal formée est refusée avant l'admission : rien n'est lu ni
     * récupéré pour une demande que league n'aurait de toute façon pas jugée.
     *
     * @param array<string, string|list<string>|null> $overrides
     */
    #[DataProvider('refusedBeforeLeague')]
    public function testAMalformedRequestNeverReachesTheAdmission(array $overrides): void
    {
        $admission = $this->createMock(CimdClientAdmissionInterface::class);
        $admission->expects(self::never())->method('admit');

        try {
            $this->validatorWith($admission)->validate($this->request($overrides));
            self::fail('La demande aurait dû être refusée.');
        } catch (OAuthServerException $exception) {
            self::assertSame('invalid_request', $exception->getErrorType());
        }
    }

    private function refusedBy(ClientMetadataRefusedException $refusal): OAuthServerException
    {
        $admission = self::createStub(CimdClientAdmissionInterface::class);
        $admission->method('admit')->willThrowException($refusal);

        try {
            $this->validatorWith($admission)->validate($this->request());
        } catch (OAuthServerException $exception) {
            return $exception;
        }

        self::fail('La demande aurait dû être refusée.');
    }

    private function discoveredClient(): McpClient
    {
        $clientId = CimdClientId::tryFrom(ClaudeCodeMetadata::CLIENT_ID) ?? throw new LogicException('Identifiant de test illisible.');

        return McpClient::discovered(
            ClientMetadataDocument::fromJson($clientId, ClaudeCodeMetadata::json()),
            new MockClock('2026-11-01 10:00:00')->now(),
        );
    }

    /**
     * Le validateur construit avec le serveur et les points d'accès du
     * conteneur, l'admission remplacée par une doublure (le service du
     * conteneur est privé : il ne se remplace pas).
     */
    private function validatorWith(CimdClientAdmissionInterface $admission): AuthorizationRequestValidatorInterface
    {
        return new AuthorizationRequestValidator(
            server: self::getContainer()->get(AuthorizationServer::class),
            endpoints: self::getContainer()->get(OAuthEndpoints::class),
            admission: $admission,
        );
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
