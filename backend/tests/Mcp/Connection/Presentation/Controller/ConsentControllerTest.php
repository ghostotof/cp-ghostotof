<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Presentation\Controller;

use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Mcp\Connection\DrivesTheOAuthFlow;
use App\Tests\Support\ReadsSecurityAuditLog;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le consentement à une connexion MCP (#647, spec 0007 phase 2),
 * `GET|POST /api/oauth/consent`, de bout en bout par HTTP.
 *
 * - Le palier de confiance seul y entre (ADR 0006 D3) : un jeton d'accès de
 *   base, un compte `ROLE_USER` sans plus, un anonyme sont refusés ; un
 *   `ROLE_SUPER` y entre par la hiérarchie des rôles.
 * - La demande est revalidée à chaque appel, par le même validateur que le
 *   point d'autorisation : la page ne fait que renvoyer ce que le serveur a
 *   jugé, et une demande devenue invalide (redirection modifiée) est un 400
 *   problem+json — jamais une redirection, jamais un code.
 * - L'accord crée la connexion et son code dans une transaction, puis écrit
 *   `mcp-connection-granted` au journal de sécurité ; le refus ne crée rien.
 * - Un nouvel accord du même compte au même client remplace l'accord actif
 *   précédent (#649, décision 2) au premier échange de son code, jamais à
 *   l'approbation (#726, D2) : révoqué `superseded`, et journalisé sur la
 *   requête de l'échange.
 *
 * Le parcours (client, compte, demande) est celui de DrivesTheOAuthFlow.
 */
final class ConsentControllerTest extends WebTestCase
{
    use DrivesTheOAuthFlow;
    use ReadsSecurityAuditLog;

    private const string PATH = self::CONSENT_PATH;

    private const string GRANTED_EVENT = 'mcp-connection-granted';
    private const string REVOKED_EVENT = 'mcp-connection-revoked';

    private const string PLAIN_USERNAME = 'plain';
    private const string SUPER_USERNAME = 'super';
    private const string OTHER_TRUSTED_USERNAME = 'other-trusted';

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
     * Ce que la personne voit avant d'accepter : le nom **déclaré** du client,
     * l'hôte où partira le code, le scope et la ressource — et le compte qui
     * accorde (#650).
     */
    public function testATrustedAccountSeesTheSummaryOfTheRequest(): void
    {
        $accountId = $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);

        $this->client->request('GET', self::PATH.'?'.http_build_query($this->validParameters()));

        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        self::assertSame([
            'client' => ['id' => self::CLIENT_ID, 'name' => self::CLIENT_NAME, 'registration' => 'preregistered'],
            'redirectHost' => 'localhost',
            'scope' => 'nominative:read',
            'resource' => $this->resource(),
            'account' => ['id' => $accountId, 'username' => self::TRUSTED_USERNAME],
        ], $this->json($response));
        $this->assertNotStored($response);
    }

    /**
     * Le compte nommé est celui dont le cookie arrive avec la requête, lu
     * côté serveur : après la connexion d'un autre compte dans le même
     * client (un autre onglet), le résumé nomme ce dernier, jamais le premier
     * que la page aurait pu garder en mémoire (#650).
     */
    public function testTheSummaryNamesTheSignedInAccount(): void
    {
        $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);
        $otherId = $this->loginAs(self::OTHER_TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);

        $this->client->request('GET', self::PATH.'?'.http_build_query($this->validParameters()));

        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        self::assertSame(['id' => $otherId, 'username' => self::OTHER_TRUSTED_USERNAME], $this->json($response)['account'] ?? null);
    }

    /**
     * F5 de la relecture de #650, le scénario : la page est ouverte sous le
     * compte A (son résumé nomme A et porte son UUID), la personne se
     * reconnecte en B dans un autre onglet — les cookies du navigateur sont
     * ceux de B —, puis clique « Accorder » dans le premier onglet, qui renvoie
     * l'UUID de A. Le serveur refuse : 409 typé, et rien n'est accordé à B
     * (ni connexion, ni code, ni ligne d'audit d'accord).
     */
    public function testAnApprovalShownToAnotherAccountIsRefusedAndGrantsNothing(): void
    {
        $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);
        $this->client->request('GET', self::PATH.'?'.http_build_query($this->validParameters()));
        $shownAccountId = $this->json($this->client->getResponse())['account']['id'] ?? null;
        self::assertIsString($shownAccountId);

        $otherId = $this->loginAs(self::OTHER_TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);
        self::assertNotSame($shownAccountId, $otherId);

        $response = $this->decide(approved: true, shownAccount: $shownAccountId);

        $this->assertAccountChanged($response);
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_connection'));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_authorization_code'));
        self::assertSame([], self::securityAuditEvents(self::GRANTED_EVENT));
    }

    /**
     * Le refus est lié comme l'accord : une décision nomme le compte pour
     * lequel elle a été prise, quelle qu'elle soit. Le coût est un clic
     * (recharger la demande, refuser sous le compte courant) ; le gain, une
     * règle unique et une réponse « refusé » qui n'est jamais celle de
     * quelqu'un d'autre.
     */
    public function testADenialShownToAnotherAccountIsRefusedToo(): void
    {
        $shownAccountId = $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);
        $this->loginAs(self::OTHER_TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);

        $response = $this->decide(approved: false, shownAccount: $shownAccountId);

        $this->assertAccountChanged($response);
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_connection'));
    }

    /**
     * Le contrat a deux applications déployées séparément : une page restée
     * ouverte pendant un déploiement (ancien frontend) poste sans l'UUID. Pas
     * de tolérance — une garde facultative se contourne en omettant le champ, et
     * aucune échéance ne la retirerait : 422 du Validator (comme un `approved`
     * absent), rien de créé. La page lit le 422 comme un corps mal formé.
     */
    #[DataProvider('provideAMissingOrMalformedShownAccount')]
    public function testADecisionWithoutAValidShownAccountIsUnprocessable(?string $shownAccount): void
    {
        $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);

        $response = $this->decide(approved: true, shownAccount: $shownAccount);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_connection'));
        self::assertSame([], self::securityAuditEvents(self::GRANTED_EVENT));
    }

    /** @return iterable<string, array{?string}> */
    public static function provideAMissingOrMalformedShownAccount(): iterable
    {
        yield 'absent' => [null];
        yield 'vide' => [''];
        yield 'pas un UUID' => ['trusted'];
    }

    /**
     * Le même compte, l'UUID écrit en majuscules : c'est le même UUID (RFC 9562
     * §4), accordé — la comparaison n'est pas une comparaison de chaînes.
     */
    public function testTheShownAccountIsComparedAsAUuidNotAsAString(): void
    {
        $accountId = $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);

        $response = $this->decide(approved: true, shownAccount: strtoupper($accountId));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        self::assertCount(1, $this->connectionRows());
    }

    /** Le 409 : problem+json typé, constant, sans redirection ni code. */
    private function assertAccountChanged(Response $response): void
    {
        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertFalse($response->headers->has('Location'));
        $body = $this->json($response);
        self::assertSame('/errors/consent-account-changed', $body['type'] ?? null);
        self::assertArrayNotHasKey('redirectUri', $body);
        // Le détail ne nomme aucun compte : il est rendu tel quel (ProblemDetailStaysStaticTest).
        $detail = $body['detail'] ?? null;
        self::assertIsString($detail);
        self::assertStringNotContainsString(self::TRUSTED_USERNAME, $detail);
        self::assertStringNotContainsString(self::OTHER_TRUSTED_USERNAME, $detail);
    }

    /**
     * L'accord : une connexion pour ce compte et ce client, un code émis
     * pour elle, renvoyé au client sur la redirection enregistrée avec
     * `state` et `iss` (RFC 9207), puis la ligne d'audit — qui ne porte pas
     * le code.
     */
    public function testAnApprovalCreatesTheConnectionAndSendsTheCodeBackToTheClient(): void
    {
        $account = $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);

        $response = $this->decide(approved: true);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        $this->assertNotStored($response);
        $redirectUri = $this->redirectUriOf($response);
        self::assertStringStartsWith(self::LOOPBACK_REDIRECT.'?', $redirectUri);
        $query = $this->queryOf($redirectUri);
        self::assertIsString($query['code'] ?? null);
        self::assertNotSame('', $query['code']);
        self::assertSame('state-1', $query['state'] ?? null);
        self::assertSame($this->publicApiBase().'/api/oauth', $query['iss'] ?? null);
        self::assertArrayNotHasKey('error', $query);

        $connections = $this->connectionRows();
        self::assertCount(1, $connections);
        self::assertSame($account, $connections[0]['account_id']);
        self::assertSame(self::CLIENT_ID, $connections[0]['client_id']);
        self::assertSame('nominative:read', $connections[0]['scope']);
        self::assertNull($connections[0]['revoked_at']);
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM mcp_authorization_code WHERE connection_id = :id', ['id' => $connections[0]['id']]));

        $audit = self::singleSecurityAuditEvent('mcp-connection-granted');
        self::assertSame($account, $audit['userId'] ?? null);
        self::assertSame($connections[0]['id'], $audit['connectionId'] ?? null);
        self::assertSame(self::CLIENT_ID, $audit['clientId'] ?? null);
        self::assertSame($account, $audit['actor'] ?? null);
        self::assertSame(self::PATH, $audit['path'] ?? null);
        // Un message qui ne cite pas le code : un échec le publierait dans le
        // journal de la CI.
        self::assertFalse(str_contains(json_encode(self::securityAuditRecords(), \JSON_THROW_ON_ERROR), $query['code']), 'Le journal d\'audit cite le code.');
    }

    /** Le refus : `access_denied` au client, `state` et `iss` compris, et rien de créé. */
    public function testADenialSendsAccessDeniedBackToTheClientAndCreatesNothing(): void
    {
        $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);

        $response = $this->decide(approved: false);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        $this->assertNotStored($response);
        $redirectUri = $this->redirectUriOf($response);
        self::assertStringStartsWith(self::LOOPBACK_REDIRECT.'?', $redirectUri);
        $query = $this->queryOf($redirectUri);
        self::assertSame('access_denied', $query['error'] ?? null);
        self::assertSame('state-1', $query['state'] ?? null);
        self::assertSame($this->publicApiBase().'/api/oauth', $query['iss'] ?? null);
        self::assertArrayNotHasKey('code', $query);
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_connection'));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_authorization_code'));
        self::assertSame([], self::securityAuditEvents('mcp-connection-granted'));
    }

    /**
     * Un scope répété (`nominative:read nominative:read`, que league admet
     * scope par scope) : le résumé montre le seul scope accordé, et la
     * connexion n'enregistre que lui — jamais la concaténation reçue.
     */
    public function testARepeatedScopeIsSummarisedAndStoredOnce(): void
    {
        $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);
        $overrides = ['scope' => 'nominative:read nominative:read'];

        $this->client->request('GET', self::PATH.'?'.http_build_query($this->validParameters($overrides)));
        $summary = $this->client->getResponse();
        self::assertSame(Response::HTTP_OK, $summary->getStatusCode(), substr((string) $summary->getContent(), 0, 500));
        self::assertSame('nominative:read', $this->json($summary)['scope'] ?? null);

        $response = $this->decide(approved: true, overrides: $overrides);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        self::assertSame('nominative:read', $this->connectionRows()[0]['scope'] ?? null);
    }

    /**
     * Une authentification de plus de dix minutes n'accorde rien (F1 de la
     * relecture de #647) : le cookie `BEARER` d'une personne (JWT d'une heure,
     * non révocable, ADR 0003) ne suffit pas, volé, à ouvrir une connexion de
     * trente jours. L'instant est le `iat` du jeton signé, jamais une valeur
     * que le client enverrait : un jeton forgé ici, signé par la clé du site,
     * onze minutes dans le passé. 401 problem+json typé, ni connexion, ni code,
     * ni ligne d'audit d'accord, et le corps ne porte aucune redirection.
     */
    public function testAnApprovalOnAnAuthenticationOlderThanTheLimitRequiresReauthentication(): void
    {
        $account = $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);
        $this->replaceTheBearerWithAnAuthenticationOf($account, secondsAgo: 660);

        $response = $this->decide(approved: true);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        $body = $this->json($response);
        self::assertSame('/errors/reauthentication-required', $body['type'] ?? null);
        self::assertArrayNotHasKey('redirectUri', $body);
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_connection'));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_authorization_code'));
        self::assertSame([], self::securityAuditEvents('mcp-connection-granted'));
    }

    /**
     * Sur la même authentification ancienne, lire le résumé et refuser
     * restent permis : ni l'un ni l'autre n'accorde quoi que ce soit, et la
     * page doit pouvoir montrer la demande avant de renvoyer à la connexion.
     */
    public function testTheSummaryAndADenialNeedNoRecentAuthentication(): void
    {
        $account = $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);
        $this->replaceTheBearerWithAnAuthenticationOf($account, secondsAgo: 660);

        $this->client->request('GET', self::PATH.'?'.http_build_query($this->validParameters()));
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $response = $this->decide(approved: false);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        self::assertSame('access_denied', $this->queryOf($this->redirectUriOf($response))['error'] ?? null);
    }

    /**
     * Une authentification récente, mais pas toute fraîche (cinq minutes) :
     * l'accord passe — la borne laisse le temps de lire la page.
     */
    public function testAnApprovalOnARecentAuthenticationIsGranted(): void
    {
        $account = $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);
        $this->replaceTheBearerWithAnAuthenticationOf($account, secondsAgo: 300);

        $response = $this->decide(approved: true);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        self::assertCount(1, $this->connectionRows());
    }

    /**
     * F2 de la relecture de #650, le côté qui passe : un mot de passe changé
     * **avant** l'authentification de la session (changement il y a deux minutes,
     * connexion il y a une) ne la périme pas — le résumé et l'accord sont servis.
     * Le côté qui refuse, avec les deux écrivains réels du mot de passe, est dans
     * `AccountWideRevocationTest`.
     */
    public function testASessionAuthenticatedAfterThePasswordChangeIsServed(): void
    {
        $account = $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);
        $this->replaceTheBearerWithAnAuthenticationOf($account, secondsAgo: 60);
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement(
            "UPDATE cpg_user SET credentials_changed_at = now() - INTERVAL '2 minutes' WHERE id = :id",
            ['id' => $account],
        );

        $this->client->request('GET', self::PATH.'?'.http_build_query($this->validParameters()));
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), substr((string) $this->client->getResponse()->getContent(), 0, 500));

        $response = $this->decide(approved: true);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        self::assertCount(1, $this->connectionRows());
    }

    /**
     * Un second accord du même compte au même client remplace le premier
     * **au premier échange de son code** (#726, D2) : l'approbation ne révoque
     * rien — sa requête n'audite que `mcp-connection-granted`, la première
     * connexion reste active. L'échange du second code révoque la première,
     * cause `superseded`, dans la transaction du point de jeton ; la ligne
     * `mcp-connection-revoked` est lue sur la requête de l'échange, acteur
     * `anonymous` (le firewall `oauth` n'authentifie personne), et nomme la
     * connexion qui remplace (`supersededBy`, celle du code échangé). L'ancien
     * rafraîchissement n'obtient plus rien (`invalid_grant`, sans seconde
     * ligne). Ni code ni jeton dans aucune ligne.
     */
    public function testASecondGrantSupersedesTheFirstAtItsExchange(): void
    {
        $account = $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);
        $issued = $this->exchange($this->codeGrantedByTheSignedInAccount());
        self::assertSame(Response::HTTP_OK, $issued->getStatusCode(), substr((string) $issued->getContent(), 0, 500));
        $firstRefreshToken = $this->json($issued)['refresh_token'] ?? null;
        self::assertIsString($firstRefreshToken);
        $firstConnectionId = $this->connectionRows()[0]['id'];

        $secondCode = $this->codeGrantedByTheSignedInAccount();

        $granted = $this->grantAndRevocationEvents();
        self::assertSame([self::GRANTED_EVENT], array_column($granted, 'event'), 'L\'approbation ne révoque plus rien (#726).');
        self::assertCount(2, $this->activeConnectionIds());
        $secondConnectionId = $granted[0]['connectionId'] ?? null;
        self::assertIsString($secondConnectionId);
        self::assertNotSame($firstConnectionId, $secondConnectionId);

        $exchanged = $this->exchange($secondCode);
        self::assertSame(Response::HTTP_OK, $exchanged->getStatusCode(), substr((string) $exchanged->getContent(), 0, 500));

        $revoked = $this->grantAndRevocationEvents();
        self::assertSame([self::REVOKED_EVENT], array_column($revoked, 'event'));
        self::assertSame($account, $revoked[0]['userId'] ?? null);
        self::assertSame($firstConnectionId, $revoked[0]['connectionId'] ?? null);
        self::assertSame(self::CLIENT_ID, $revoked[0]['clientId'] ?? null);
        self::assertSame('superseded', $revoked[0]['cause'] ?? null);
        self::assertSame('anonymous', $revoked[0]['actor'] ?? null);
        // La connexion qui remplace (#726, B3) : celle du code échangé, seul
        // lien entre la coupure et l'accord qui l'a causée.
        self::assertSame($secondConnectionId, $revoked[0]['supersededBy'] ?? null);
        $logged = json_encode([...$granted, ...$revoked], \JSON_THROW_ON_ERROR);
        // Des messages qui ne citent pas la valeur : un échec la publierait
        // dans le journal de la CI.
        self::assertFalse(str_contains($logged, $secondCode), 'Le journal d\'audit cite le second code.');
        self::assertFalse(str_contains($logged, $firstRefreshToken), 'Le journal d\'audit cite le premier rafraîchissement.');
        self::assertSame([$secondConnectionId], $this->activeConnectionIds());
        self::assertSame('superseded', $this->connectionRow($firstConnectionId)['revocation_cause']);

        $this->assertOAuthError($this->refresh($firstRefreshToken), Response::HTTP_BAD_REQUEST, 'invalid_grant');
        self::assertSame([], self::securityAuditEvents(self::REVOKED_EVENT));
        self::assertSame('superseded', $this->connectionRow($firstConnectionId)['revocation_cause']);
    }

    /**
     * La reproduction de D2 (#726) : un second accord approuvé dont le code
     * n'est **jamais** échangé (le client abandonne, la redirection échoue)
     * laisse la première connexion vivante — active, aucune ligne
     * `mcp-connection-revoked`, et son rafraîchissement servi. Révoquée à
     * l'approbation, la personne restait sans aucune connexion.
     */
    public function testAnAbandonedSecondGrantLeavesTheFirstConnection(): void
    {
        $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);
        $issued = $this->exchange($this->codeGrantedByTheSignedInAccount());
        self::assertSame(Response::HTTP_OK, $issued->getStatusCode(), substr((string) $issued->getContent(), 0, 500));
        $firstRefreshToken = $this->json($issued)['refresh_token'] ?? null;
        self::assertIsString($firstRefreshToken);
        $firstConnectionId = $this->connectionRows()[0]['id'];

        $this->codeGrantedByTheSignedInAccount();

        self::assertSame([], self::securityAuditEvents(self::REVOKED_EVENT));
        self::assertNull($this->connectionRow($firstConnectionId)['revoked_at'], 'L\'approbation a révoqué la connexion précédente (#726, D2).');
        $refreshed = $this->refresh($firstRefreshToken);
        self::assertSame(Response::HTTP_OK, $refreshed->getStatusCode(), substr((string) $refreshed->getContent(), 0, 500));
        self::assertNull($this->connectionRow($firstConnectionId)['revoked_at']);
    }

    /**
     * Le remplacement porte sur le couple compte/client : l'échange du code
     * d'un accord au même compte pour un autre client laisse la première
     * connexion active, et ne journalise aucune révocation.
     */
    public function testAGrantForAnotherClientLeavesTheFirstAlone(): void
    {
        $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);
        $this->codeGrantedByTheSignedInAccount();

        $otherCode = $this->codeGrantedByTheSignedInAccount(['client_id' => self::OTHER_CLIENT_ID]);
        self::assertSame([self::GRANTED_EVENT], array_column($this->grantAndRevocationEvents(), 'event'));
        $exchanged = $this->exchange($otherCode, ['client_id' => self::OTHER_CLIENT_ID]);
        self::assertSame(Response::HTTP_OK, $exchanged->getStatusCode(), substr((string) $exchanged->getContent(), 0, 500));

        self::assertSame([], self::securityAuditEvents(self::REVOKED_EVENT));
        self::assertCount(2, $this->activeConnectionIds());
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_connection WHERE revoked_at IS NOT NULL'));
    }

    /**
     * La même décision envoyée deux fois (le réessai d'une page après un 5xx,
     * report de T8) : deux accords, deux connexions actives tant qu'aucun
     * code n'est échangé ; l'échange de l'un n'en laisse qu'une — la sienne.
     */
    public function testARetriedApprovalLeavesOneActiveConnectionOnceExchanged(): void
    {
        $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);
        $this->client->request('GET', self::AUTHORIZE_PATH.'?'.http_build_query($this->validParameters()));
        self::assertSame(Response::HTTP_FOUND, $this->client->getResponse()->getStatusCode());

        $first = $this->decide(approved: true);
        self::assertSame(Response::HTTP_OK, $first->getStatusCode(), substr((string) $first->getContent(), 0, 500));
        $retried = $this->decide(approved: true);
        self::assertSame(Response::HTTP_OK, $retried->getStatusCode(), substr((string) $retried->getContent(), 0, 500));

        self::assertSame(2, $this->countRows('SELECT COUNT(*) FROM mcp_connection'));
        self::assertCount(2, $this->activeConnectionIds());
        $code = $this->queryOf($this->redirectUriOf($retried))['code'] ?? null;
        self::assertIsString($code);
        $exchanged = $this->exchange($code);
        self::assertSame(Response::HTTP_OK, $exchanged->getStatusCode(), substr((string) $exchanged->getContent(), 0, 500));

        self::assertCount(1, $this->activeConnectionIds());
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM mcp_connection WHERE revocation_cause = 'superseded'"));
    }

    /**
     * Deux accords, aucun échangé ; le code du plus récent s'échange d'abord
     * et remplace le premier. Le code du premier, échangé ensuite, est mort :
     * sa connexion est révoquée, league le refuse comme un code révoqué
     * (`invalid_grant`, « Authorization code has been revoked »), sans
     * révocation de plus — la seconde connexion reste active.
     */
    public function testAnOlderCodeExchangedAfterANewerOneIsRefused(): void
    {
        $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);
        $firstCode = $this->codeGrantedByTheSignedInAccount();
        $secondCode = $this->codeGrantedByTheSignedInAccount();

        $exchanged = $this->exchange($secondCode);
        self::assertSame(Response::HTTP_OK, $exchanged->getStatusCode(), substr((string) $exchanged->getContent(), 0, 500));
        self::assertCount(1, self::securityAuditEvents(self::REVOKED_EVENT));
        $active = $this->activeConnectionIds();
        self::assertCount(1, $active);

        $refused = $this->exchange($firstCode);

        $this->assertOAuthError($refused, Response::HTTP_BAD_REQUEST, 'invalid_grant');
        self::assertSame('Authorization code has been revoked', $this->json($refused)['hint'] ?? null);
        self::assertSame([], self::securityAuditEvents(self::REVOKED_EVENT));
        // Le refus d'un code authentique dont la connexion est révoquée (#725).
        self::assertSame('code-refused', self::singleSecurityAuditEvent('mcp-grant-refused')['cause'] ?? null);
        self::assertSame($active, $this->activeConnectionIds());
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM mcp_connection WHERE revoked_at IS NOT NULL'));
    }

    /**
     * Un rafraîchissement ne remplace rien : seul l'échange d'un code le
     * fait. Le premier accord échangé, le second approuvé sans échange, le
     * rafraîchissement de la première connexion est servi et la seconde reste
     * active.
     */
    public function testARefreshSupersedesNothing(): void
    {
        $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);
        $issued = $this->exchange($this->codeGrantedByTheSignedInAccount());
        self::assertSame(Response::HTTP_OK, $issued->getStatusCode(), substr((string) $issued->getContent(), 0, 500));
        $firstRefreshToken = $this->json($issued)['refresh_token'] ?? null;
        self::assertIsString($firstRefreshToken);
        $this->codeGrantedByTheSignedInAccount();

        $refreshed = $this->refresh($firstRefreshToken);

        self::assertSame(Response::HTTP_OK, $refreshed->getStatusCode(), substr((string) $refreshed->getContent(), 0, 500));
        self::assertSame([], self::securityAuditEvents(self::REVOKED_EVENT));
        self::assertCount(2, $this->activeConnectionIds());
    }

    /** `ROLE_SUPER` hérite du palier de confiance (`role_hierarchy`). */
    public function testASuperAdministratorMayGrantAConnectionThroughTheRoleHierarchy(): void
    {
        $account = $this->loginAs(self::SUPER_USERNAME, [CpgUser::ROLE_SUPER]);

        $response = $this->decide(approved: true);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        self::assertArrayHasKey('code', $this->queryOf($this->redirectUriOf($response)));
        self::assertSame($account, $this->connectionRows()[0]['account_id'] ?? null);
    }

    /**
     * La redirection modifiée après l'autorisation (la page, ou quiconque
     * rejoue le POST, en propose une autre que celle validée) : la demande
     * revalidée ne l'est plus. Un 400 problem+json, jamais une redirection
     * vers l'URI proposée, ni vers une autre, et rien de créé.
     *
     * @return iterable<string, array{array<string, string|null>}>
     */
    public static function requestsNoLongerValid(): iterable
    {
        yield 'redirection non enregistrée' => [['redirect_uri' => 'https://attacker.example.test/callback']];
        yield 'client inconnu' => [['client_id' => 'https://unknown.example.test/metadata.json']];
        yield 'ressource absente' => [['resource' => null]];
        yield 'ressource étrangère' => [['resource' => 'https://other.example.test/api/mcp']];
        yield 'PKCE plain' => [['code_challenge_method' => 'plain']];
        yield 'sans défi PKCE' => [['code_challenge' => null, 'code_challenge_method' => null]];
    }

    /**
     * @param array<string, string|null> $overrides
     */
    #[DataProvider('requestsNoLongerValid')]
    public function testARequestNoLongerValidIsA400ProblemWithoutRedirectNorConnection(array $overrides): void
    {
        $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);

        foreach ([true, false] as $approved) {
            $response = $this->decide(approved: $approved, overrides: $overrides);

            $this->assertRejected($response);
        }

        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_connection'));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_authorization_code'));
    }

    /**
     * Le résumé d'une demande invalide : le même 400, jamais le JSON de league.
     *
     * @param array<string, string|null> $overrides
     */
    #[DataProvider('requestsNoLongerValid')]
    public function testTheSummaryOfARequestNoLongerValidIsTheSame400Problem(array $overrides): void
    {
        $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);

        $this->client->request('GET', self::PATH.'?'.http_build_query($this->validParameters($overrides)));

        $this->assertRejected($this->client->getResponse());
    }

    /** Le jeton d'accès de base (ADR 0003 D6) n'est pas le palier de confiance. */
    public function testTheBaseTierIsForbidden(): void
    {
        $this->client->request('POST', '/api/account/base-access', server: ['HTTP_X_REQUESTED_WITH' => 'fetch']);
        self::assertResponseIsSuccessful();

        $this->assertBothRoutesAnswer(Response::HTTP_FORBIDDEN, $this->xsrfToken());
    }

    /** Un compte réel sans `ROLE_TRUSTED` : le palier de base, rien de plus. */
    public function testAnAccountWithoutRoleTrustedIsForbidden(): void
    {
        $this->loginAs(self::PLAIN_USERNAME, []);

        $this->assertBothRoutesAnswer(Response::HTTP_FORBIDDEN, $this->xsrfToken());
    }

    /**
     * Anonyme : le firewall répond 401 au GET ; le POST est refusé avant lui
     * par la garde CSRF (priorité 20), 403.
     */
    public function testAnAnonymousCallerIsRefused(): void
    {
        $this->client->request('GET', self::PATH.'?'.http_build_query($this->validParameters()));
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());

        $this->postConsent(['approved' => true, ...$this->decisionBody()], null);
        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_connection'));
    }

    /**
     * Une mutation sans l'en-tête du double-submit CSRF : refusée, même au
     * palier de confiance — et par la garde CSRF, qui le trace.
     */
    public function testAnApprovalWithoutTheCsrfHeaderIsForbidden(): void
    {
        $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);

        $this->postConsent(['approved' => true, ...$this->decisionBody()], null);

        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
        $rejection = self::singleSecurityAuditEvent('csrf-rejected');
        self::assertSame(self::PATH, $rejection['path'] ?? null);
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_connection'));
    }

    /** Une décision sans `approved` n'est ni un accord ni un refus : 422, rien de créé. */
    public function testADecisionWithoutApprovedIsUnprocessable(): void
    {
        $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);

        $this->postConsent($this->decisionBody(), $this->xsrfToken());

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_connection'));
    }

    private function assertBothRoutesAnswer(int $status, ?string $xsrfToken): void
    {
        $this->client->request('GET', self::PATH.'?'.http_build_query($this->validParameters()));
        self::assertSame($status, $this->client->getResponse()->getStatusCode(), 'GET');

        $this->postConsent(['approved' => true, ...$this->decisionBody()], $xsrfToken);
        self::assertSame($status, $this->client->getResponse()->getStatusCode(), 'POST');
        // L'en-tête XSRF est valide : le refus est celui de l'access_control,
        // jamais celui de la garde CSRF, qui l'aurait tracé (`csrf-rejected`).
        self::assertSame([], self::securityAuditEvents('csrf-rejected'), 'Le POST doit passer la garde CSRF et buter sur l\'access_control.');
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_connection'));
    }

    /**
     * Le refus de revalidation : 400 problem+json typé, sans `redirectUri` ni
     * `Location` — on ne redirige jamais au consentement une demande que le
     * serveur ne juge plus valide.
     */
    private function assertRejected(Response $response): void
    {
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertFalse($response->headers->has('Location'));
        $body = $this->json($response);
        self::assertSame('/errors/authorization-request-rejected', $body['type'] ?? null);
        self::assertArrayNotHasKey('redirectUri', $body);
        self::assertArrayNotHasKey('error', $body);
    }

    /**
     * Remplace le cookie `BEARER` du compte par un JWT signé par la clé du
     * site, émis `$secondsAgo` secondes plus tôt (`iat`), valable une heure à
     * partir de maintenant : seul l'instant d'authentification change.
     */
    private function replaceTheBearerWithAnAuthenticationOf(string $accountId, int $secondsAgo): void
    {
        $account = self::getContainer()->get(EntityManagerInterface::class)->find(CpgUser::class, $accountId);
        self::assertInstanceOf(CpgUser::class, $account);
        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class)->createFromPayload($account, ['iat' => time() - $secondsAgo]);

        $jar = $this->client->getCookieJar();
        $issued = $jar->get('BEARER') ?? self::fail('Aucun cookie BEARER après la connexion.');
        $jar->set(new Cookie('BEARER', $jwt, null, $issued->getPath(), $issued->getDomain(), $issued->isSecure(), $issued->isHttpOnly(), false, $issued->getSameSite()));
    }

    /**
     * Les connexions en base, avec l'identifiant public de leur client.
     *
     * @return list<array{id: string, account_id: string, client_id: string, scope: string, revoked_at: string|null, revocation_cause: string|null}>
     */
    private function connectionRows(): array
    {
        /** @var list<array{id: string, account_id: string, client_id: string, scope: string, revoked_at: string|null, revocation_cause: string|null}> */
        return self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchAllAssociative(
            'SELECT c.id, c.account_id, cl.client_id, c.scope, c.revoked_at, c.revocation_cause FROM mcp_connection c INNER JOIN mcp_client cl ON cl.id = c.mcp_client_id ORDER BY c.id',
        );
    }

    /**
     * Une connexion en base, par son identifiant.
     *
     * @return array{id: string, account_id: string, client_id: string, scope: string, revoked_at: string|null, revocation_cause: string|null}
     */
    private function connectionRow(string $id): array
    {
        foreach ($this->connectionRows() as $row) {
            if ($id === $row['id']) {
                return $row;
            }
        }

        self::fail(\sprintf('Aucune connexion %s en base.', $id));
    }

    /**
     * Les identifiants des connexions actives (non révoquées).
     *
     * @return list<string>
     */
    private function activeConnectionIds(): array
    {
        return array_column(array_filter($this->connectionRows(), static fn(array $row): bool => null === $row['revoked_at']), 'id');
    }

    /**
     * Les lignes d'accord et de révocation de la dernière requête, dans
     * l'ordre où elles ont été écrites.
     *
     * @return list<array<string, mixed>>
     */
    private function grantAndRevocationEvents(): array
    {
        $events = [];

        foreach (self::securityAuditRecords() as $record) {
            /** @var array<string, mixed> $context */
            $context = $record->context;

            if (\in_array($context['event'] ?? null, [self::GRANTED_EVENT, self::REVOKED_EVENT], true)) {
                $events[] = $context;
            }
        }

        return $events;
    }
}
