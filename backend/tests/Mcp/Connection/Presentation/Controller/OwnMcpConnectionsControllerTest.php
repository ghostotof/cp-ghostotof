<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Presentation\Controller;

use App\Mcp\Connection\Application\OwnMcpConnections;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Mcp\Support\HeldMcpConnection;
use App\Tests\Mcp\Support\HoldsAnMcpConnection;
use App\Tests\Mcp\Support\SpeaksMcp;
use App\Tests\Support\ReadsAllChannelsLog;
use App\Tests\Support\ReadsSecurityAuditLog;
use App\Tests\Support\TestCredentials;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Level;
use Monolog\LogRecord;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * « Mes connexions MCP » (#650, spec 0007 tâche 11) : `GET /api/me/mcp-connections`
 * liste les connexions de la personne qui appelle, de bout en bout par HTTP.
 *
 * - Les siennes seulement, celles d'un autre compte jamais ; les révoquées et
 *   les expirées que la purge garde six mois y figurent avec leur état et leur
 *   cause (question 1 du plan, tranchée par l'auteur).
 * - L'état se juge par `McpConnection::isExpiredAt()`, la seule définition de
 *   l'échéance : une connexion échue que la purge n'a pas encore marquée est
 *   `expired`, pas `active` ; `revoked_at` seul ne dit rien (commentaire de #649).
 * - Les accords abandonnés s'accumulent et sont tous listés : c'est ce que la
 *   personne doit voir pour les révoquer (#730).
 * - Palier de confiance seul (règle `^/api/me(/|$)`) : un anonyme est refusé en
 *   401, le palier de base en 403. Aucun jeton, aucune empreinte dans le corps.
 *
 * Les connexions sont accordées par le vrai parcours OAuth
 * ({@see HoldsAnMcpConnection}) ; les états particuliers s'obtiennent en
 * altérant la ligne en SQL.
 */
final class OwnMcpConnectionsControllerTest extends WebTestCase
{
    use HoldsAnMcpConnection;
    use ReadsAllChannelsLog;
    use ReadsSecurityAuditLog;
    use SpeaksMcp;

    private const string PATH = '/api/me/mcp-connections';

    private const string OTHER_TRUSTED_USERNAME = 'other-trusted';

    private const string REVOKED_EVENT = 'mcp-connection-revoked';

    private const string NOT_AUDITED = 'Revoked connection not audited.';

    /** Les clés exactes d'une entrée de la liste, dans l'ordre où l'API les écrit. */
    private const array ENTRY_KEYS = ['id', 'client', 'grantedAt', 'lastUsedAt', 'expiresAt', 'state', 'revokedAt', 'revocationCause'];

    protected function setUp(): void
    {
        $this->setUpOAuthFlow();
    }

    protected function tearDown(): void
    {
        self::forgetMcpSessions(self::getContainer()->get(EntityManagerInterface::class)->getConnection());
        $this->tearDownOAuthFlow();
        parent::tearDown();
    }

    /**
     * Deux connexions du compte `trusted`, une du compte `other-trusted` : la
     * liste du premier en a deux, et l'identifiant de l'autre n'y est pas.
     */
    public function testItListsOnlyTheCallersConnections(): void
    {
        $first = $this->holdMcpConnection();
        $second = $this->holdAnotherMcpConnection();
        $foreign = $this->holdConnectionOfAnotherAccount();

        $entries = $this->listAsTrusted();

        $ids = array_map(static fn(array $entry): mixed => $entry['id'], $entries);
        sort($ids);
        $expected = [$first->connectionId, $second->connectionId];
        sort($expected);
        self::assertSame($expected, $ids);
        self::assertNotContains($foreign->connectionId, $ids);
    }

    /** La forme exacte de la réponse : aucune clé de plus (jamais un jeton, un scope ni un compte), aucune de moins. */
    public function testTheShapeIsExact(): void
    {
        $held = $this->holdMcpConnection();

        $response = $this->listResponseAsTrusted();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        $this->assertNotStored($response);
        self::assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        $body = $this->json($response);
        self::assertSame(['connections', 'terminatedTruncated'], array_keys($body));
        self::assertFalse($body['terminatedTruncated']);
        self::assertIsList($body['connections']);
        self::assertCount(1, $body['connections']);
        $entry = $body['connections'][0];
        self::assertIsArray($entry);
        self::assertSame(self::ENTRY_KEYS, array_keys($entry));
        self::assertSame($held->connectionId, $entry['id']);
        self::assertSame(['id' => self::CLIENT_ID, 'name' => self::CLIENT_NAME], $entry['client']);
        self::assertNull($entry['lastUsedAt'], 'L\'échange du code n\'écrit pas le dernier usage (au rafraîchissement seulement).');
        self::assertSame('active', $entry['state']);
        self::assertNull($entry['revokedAt']);
        self::assertNull($entry['revocationCause']);

        // Les instants sont rendus dans le fuseau de l'horloge (Paris), en ATOM.
        self::assertIsString($entry['grantedAt']);
        self::assertIsString($entry['expiresAt']);
        $grantedAt = $this->instant($entry['grantedAt']);
        self::assertSame($entry['grantedAt'], $grantedAt->setTimezone(new DateTimeZone('Europe/Paris'))->format(DATE_ATOM));
        self::assertSame($entry['expiresAt'], $this->instant($entry['expiresAt'])->setTimezone(new DateTimeZone('Europe/Paris'))->format(DATE_ATOM));
        // Jamais utilisée : l'inactivité (30 jours) devance la durée maximale (90).
        self::assertSame(
            $grantedAt->setTimezone(new DateTimeZone('Europe/Paris'))->add(new DateInterval('P30D'))->getTimestamp(),
            $this->instant($entry['expiresAt'])->getTimestamp(),
        );
    }

    /**
     * L'échéance affichée est la plus proche des deux : octroyée il y a 80
     * jours et utilisée hier, l'inactivité (29 jours) est loin, la durée
     * maximale (dans 10 jours) l'emporte.
     */
    public function testTheExpiryIsTheEarliestOfIdleAndMaximum(): void
    {
        $held = $this->holdMcpConnection();
        $this->alterHeldConnection($held, "granted_at = now() - INTERVAL '80 days', last_used_at = now() - INTERVAL '1 day'");

        $entry = $this->singleEntryAsTrusted();

        self::assertSame('active', $entry['state']);
        self::assertIsString($entry['grantedAt']);
        self::assertIsString($entry['expiresAt']);
        self::assertIsString($entry['lastUsedAt']);
        self::assertSame(
            // Trente jours ou quatre-vingt-dix sont des jours de calendrier de Paris : 2 161 heures à travers le passage à l'heure d'hiver.
            $this->instant($entry['grantedAt'])->setTimezone(new DateTimeZone('Europe/Paris'))->add(new DateInterval('P90D'))->getTimestamp(),
            $this->instant($entry['expiresAt'])->getTimestamp(),
        );
    }

    /**
     * Octroyée il y a 91 jours, jamais révoquée : la purge ne l'a pas encore
     * marquée, mais elle est échue — `expired`, sans révocation ni cause (Review
     * Focus 1). `revoked_at` seul ne dit pas l'état.
     */
    public function testAnExpiredButUnmarkedConnectionIsListedExpired(): void
    {
        $held = $this->holdMcpConnection();
        $this->alterHeldConnection($held, "granted_at = granted_at - INTERVAL '91 days'");

        $entry = $this->singleEntryAsTrusted();

        self::assertSame('expired', $entry['state']);
        self::assertNull($entry['revokedAt']);
        self::assertNull($entry['revocationCause']);
    }

    /**
     * Une connexion révoquée dit sa cause ; une marquée `expired` par la purge
     * est `expired` (pas `revoked`), avec l'instant et la cause de son marquage.
     */
    public function testARevokedConnectionShowsItsCause(): void
    {
        $superseded = $this->holdMcpConnection();
        $expired = $this->holdAnotherMcpConnection();
        $this->alterHeldConnection($superseded, "revoked_at = now(), revocation_cause = 'superseded'");
        $this->alterHeldConnection($expired, "revoked_at = now(), revocation_cause = 'expired'");

        $entries = $this->listAsTrusted();

        $byId = [];
        foreach ($entries as $entry) {
            self::assertIsString($entry['id']);
            $byId[$entry['id']] = $entry;
        }
        self::assertSame('revoked', $byId[$superseded->connectionId]['state']);
        self::assertSame('superseded', $byId[$superseded->connectionId]['revocationCause']);
        self::assertIsString($byId[$superseded->connectionId]['revokedAt']);
        self::assertSame('expired', $byId[$expired->connectionId]['state']);
        self::assertSame('expired', $byId[$expired->connectionId]['revocationCause']);
        self::assertIsString($byId[$expired->connectionId]['revokedAt']);
    }

    /**
     * Deux approbations au même client, aucun code échangé (#730) : deux
     * connexions actives, et la personne les voit toutes les deux.
     */
    public function testAccumulatedGrantsAreListed(): void
    {
        $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);
        $this->codeGrantedByTheSignedInAccount();
        $this->codeGrantedByTheSignedInAccount();

        $this->client->request('GET', self::PATH, server: ['HTTP_ACCEPT' => 'application/json']);
        $entries = $this->entriesOf($this->client->getResponse());

        self::assertCount(2, $entries);
        foreach ($entries as $entry) {
            self::assertSame('active', $entry['state']);
            self::assertSame(['id' => self::CLIENT_ID, 'name' => self::CLIENT_NAME], $entry['client']);
        }
    }

    /**
     * Borne de l'historique (#650, R3) : cinquante et une terminées, deux non
     * révoquées dont une **plus ancienne que toutes les terminées** — la liste
     * rend les deux non révoquées, les cinquante terminées les plus
     * récemment octroyées (jamais la cinquante et unième), et le dit par
     * `terminatedTruncated`.
     */
    public function testTheListKeepsEveryNonRevokedConnectionAndTheLastFiftyTerminated(): void
    {
        $old = $this->holdMcpConnection();
        $this->alterHeldConnection($old, "granted_at = now() - INTERVAL '80 days', last_used_at = now() - INTERVAL '1 day'");
        $recent = $this->holdAnotherMcpConnection();
        $this->insertTerminatedConnectionsLike($recent, OwnMcpConnections::TERMINATED_LIMIT + 1);
        $expectedTerminated = \array_slice($this->terminatedIdsNewestFirst(), 0, OwnMcpConnections::TERMINATED_LIMIT);

        $response = $this->listResponseAsTrusted();

        $entries = $this->entriesOf($response);
        self::assertTrue($this->json($response)['terminatedTruncated']);
        self::assertCount(2 + OwnMcpConnections::TERMINATED_LIMIT, $entries);
        $ids = array_map(static fn(array $entry): mixed => $entry['id'], $entries);
        self::assertContains($old->connectionId, $ids, 'Une non révoquée ancienne est toujours rendue.');
        self::assertContains($recent->connectionId, $ids);
        $terminatedIds = array_values(array_filter(
            array_map(static fn(array $entry): mixed => 'revoked' === $entry['state'] ? $entry['id'] : null, $entries),
            static fn(mixed $id): bool => null !== $id,
        ));
        self::assertSame($expectedTerminated, $terminatedIds, 'Les cinquante terminées les plus récemment octroyées, pas une de plus.');
        self::assertSame($ids[0], $recent->connectionId, 'L\'ordre est celui de l\'octroi décroissant, les deux moitiés mêlées.');
        self::assertSame($old->connectionId, $ids[\count($ids) - 1], 'L\'ancienne non révoquée vient après les terminées plus récentes qu\'elle.');
        // La coupe est faite par la base, pas en PHP après avoir tout hydraté : une ligne de plus que la limite est lue, pas toutes.
        $limits = array_values(array_filter($this->executedSql(), static fn(string $sql): bool => str_contains($sql, 'mcp_connection') && str_contains($sql, 'LIMIT '.(OwnMcpConnections::TERMINATED_LIMIT + 1))));
        self::assertCount(1, $limits, implode("\n", $this->executedSql()));
    }

    /** Exactement cinquante terminées : rien n'est coupé, `terminatedTruncated` est faux. */
    public function testExactlyFiftyTerminatedConnectionsAreNotATruncation(): void
    {
        $recent = $this->holdMcpConnection();
        $this->insertTerminatedConnectionsLike($recent, OwnMcpConnections::TERMINATED_LIMIT);

        $response = $this->listResponseAsTrusted();

        self::assertFalse($this->json($response)['terminatedTruncated']);
        self::assertCount(1 + OwnMcpConnections::TERMINATED_LIMIT, $this->entriesOf($response));
    }

    /** Le palier de base (ADR 0003 D6) n'est pas le palier de confiance. */
    public function testTheBaseTierIsForbidden(): void
    {
        $this->client->request('POST', '/api/account/base-access', server: ['HTTP_X_REQUESTED_WITH' => 'fetch']);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', self::PATH, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testAnAnonymousCallerIsUnauthorized(): void
    {
        $this->client->request('GET', self::PATH, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Le coût de la liste (#650, R3) : une requête par moitié — les non
     * révoquées, les terminées bornées —, au plus deux, quel que soit le nombre
     * de connexions ; le client est joint à chacune, jamais lu à la demande
     * (une requête par connexion).
     */
    public function testTheListCostsAtMostTwoQueries(): void
    {
        $this->holdMcpConnection();
        $this->holdAnotherMcpConnection();
        $this->signInAs(self::TRUSTED_USERNAME, TestCredentials::superPassword());
        $this->codeGrantedByTheSignedInAccount();

        $this->client->request('GET', self::PATH, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        self::assertCount(3, $this->entriesOf($this->client->getResponse()));
        $statements = array_values(array_filter($this->executedSql(), static fn(string $sql): bool => str_contains($sql, 'mcp_')));
        self::assertLessThanOrEqual(2, \count($statements), implode("\n", $statements));
        foreach ($statements as $statement) {
            self::assertStringContainsString('mcp_client', $statement, 'Le client est joint à la connexion.');
        }
    }

    /** Ni le jeton d'accès ni son empreinte, ni le jeton de rafraîchissement : le corps ne les porte pas. */
    public function testNoTokenReachesTheBody(): void
    {
        $held = $this->holdMcpConnection();

        $response = $this->listResponseAsTrusted();

        $content = (string) $response->getContent();
        self::assertStringNotContainsString($held->accessToken, $content);
        self::assertStringNotContainsString(hash('sha256', $held->accessToken), $content);
        self::assertStringNotContainsString('token', strtolower($content));
    }

    /**
     * Le critère de #650, de bout en bout : la personne révoque sa connexion
     * depuis le site (cookies, CSRF), et l'appel nominatif **suivant** du
     * client, dans sa session MCP déjà ouverte, est refusé en 401
     * `invalid_token` ; son rafraîchissement est un `invalid_grant`, sans
     * nouvelle ligne de révocation. Avant, le même appel n'était pas refusé :
     * la révocation est ce qui change la réponse.
     */
    public function testRevokingOwnConnectionCutsTheNextNominativeCall(): void
    {
        $issued = $this->exchange($this->grantedCode());
        $held = $this->heldMcpConnectionOf($issued);
        $refreshToken = $this->json($issued)['refresh_token'] ?? null;
        self::assertIsString($refreshToken);
        $sessionId = $this->initializeMcpSession($this->client, self::bearer($held->accessToken));
        $this->callNominativeCorpus($sessionId, $held);
        self::assertNotSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode(), 'Avant la révocation, la connexion est acceptée.');

        $this->signInAs(self::TRUSTED_USERNAME, TestCredentials::superPassword());
        $response = $this->revoke($held->connectionId);
        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        $this->client->getCookieJar()->clear();

        $this->callNominativeCorpus($sessionId, $held);
        $refused = $this->client->getResponse();
        self::assertSame(Response::HTTP_UNAUTHORIZED, $refused->getStatusCode());
        self::assertStringContainsString('error="invalid_token"', (string) $refused->headers->get('WWW-Authenticate'));

        $refreshed = $this->refresh($refreshToken);
        $this->assertOAuthError($refreshed, Response::HTTP_BAD_REQUEST, 'invalid_grant');
        self::assertSame([], self::securityAuditEvents(self::REVOKED_EVENT), 'Un rafraîchissement refusé ne révoque rien une seconde fois.');
    }

    /**
     * Une seule ligne `mcp-connection-revoked`, lue sur la requête du DELETE :
     * la cause `person`, le compte, la connexion, le client par son
     * identifiant public, l'acteur — la personne, désignée par son UUID. Aucune
     * sur la requête suivante.
     */
    public function testTheRevocationIsAuditedOnce(): void
    {
        $held = $this->holdMcpConnection();
        $this->signInAs(self::TRUSTED_USERNAME, TestCredentials::superPassword());

        $response = $this->revoke($held->connectionId);

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        $event = self::singleSecurityAuditEvent(self::REVOKED_EVENT);
        self::assertSame('person', $event['cause'] ?? null);
        self::assertSame($held->accountId, $event['userId'] ?? null);
        self::assertSame($held->connectionId, $event['connectionId'] ?? null);
        self::assertSame(self::CLIENT_ID, $event['clientId'] ?? null);
        self::assertSame($held->accountId, $event['actor'] ?? null);
        self::assertSame('person', $this->causeOf($held));
        self::assertNotNull($this->revokedAtOf($held));

        $this->client->request('GET', self::PATH, server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertSame([], self::securityAuditEvents(self::REVOKED_EVENT), 'La lecture suivante n\'écrit aucune ligne.');
    }

    /**
     * DELETE est idempotent : une connexion à soi déjà révoquée répond 204,
     * garde sa première révocation (instant et cause) et n'écrit aucune ligne.
     */
    public function testRevokingAnAlreadyRevokedConnectionIsIdempotent(): void
    {
        $held = $this->holdMcpConnection();
        $this->alterHeldConnection($held, "revoked_at = now() - INTERVAL '1 hour', revocation_cause = 'client'");
        $firstRevokedAt = $this->revokedAtOf($held);
        $this->signInAs(self::TRUSTED_USERNAME, TestCredentials::superPassword());

        $response = $this->revoke($held->connectionId);

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        self::assertSame('client', $this->causeOf($held));
        self::assertSame($firstRevokedAt, $this->revokedAtOf($held));
        self::assertSame([], self::securityAuditEvents(self::REVOKED_EVENT));
    }

    /**
     * Échue (91 jours) mais pas encore marquée par la purge : la personne la
     * voit `expired`, et un DELETE direct la révoque tout de même — cause
     * `person`, auditée (décision de l'auteur, question 6 du plan).
     */
    public function testRevokingAnExpiredUnmarkedConnectionRecordsPerson(): void
    {
        $held = $this->holdMcpConnection();
        $this->alterHeldConnection($held, "granted_at = granted_at - INTERVAL '91 days'");
        $this->signInAs(self::TRUSTED_USERNAME, TestCredentials::superPassword());

        $response = $this->revoke($held->connectionId);

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        self::assertSame('person', $this->causeOf($held));
        self::assertSame('person', self::singleSecurityAuditEvent(self::REVOKED_EVENT)['cause'] ?? null);
    }

    /**
     * Pas d'oracle sur l'existence d'un UUID : la connexion d'un autre compte
     * et un UUID inconnu répondent le même 404, au corps identique hors
     * `trace`. La connexion de l'autre reste active, aucune ligne n'est écrite.
     */
    public function testAnotherAccountsConnectionIsIndistinguishableFromAnUnknownOne(): void
    {
        $foreign = $this->holdConnectionOfAnotherAccount();
        $trusted = $this->loginAs(self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);
        self::assertNotSame($foreign->accountId, $trusted);

        $foreignAnswer = $this->revoke($foreign->connectionId);
        $foreignBody = $this->problemBody($foreignAnswer);
        self::assertSame([], self::securityAuditEvents(self::REVOKED_EVENT));
        $unknownAnswer = $this->revoke(Uuid::v7()->toRfc4122());
        $unknownBody = $this->problemBody($unknownAnswer);

        self::assertSame(Response::HTTP_NOT_FOUND, $foreignAnswer->getStatusCode(), substr((string) $foreignAnswer->getContent(), 0, 500));
        self::assertSame(Response::HTTP_NOT_FOUND, $unknownAnswer->getStatusCode());
        self::assertSame($unknownBody, $foreignBody, 'Le corps ne doit pas dire si l\'UUID existe.');
        $type = $foreignBody['type'] ?? null;
        self::assertIsString($type);
        self::assertStringEndsWith('/errors/mcp-connection-not-found', $type);
        self::assertNull($this->causeOf($foreign), 'La connexion de l\'autre compte reste active.');
        self::assertNull($this->revokedAtOf($foreign));
        self::assertSame([], self::securityAuditEvents(self::REVOKED_EVENT));
    }

    /** Un identifiant en majuscules ou mal formé n'atteint pas le contrôleur : 404 du routeur. */
    public function testAMalformedOrUpperCaseIdIsARouterNotFound(): void
    {
        $held = $this->holdMcpConnection();
        $this->signInAs(self::TRUSTED_USERNAME, TestCredentials::superPassword());

        foreach ([strtoupper($held->connectionId), 'not-a-uuid'] as $id) {
            $response = $this->revoke($id);
            self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode(), $id);
            // Le 404 est celui du routeur (la contrainte de la route), pas celui du contrôleur.
            self::assertStringNotContainsString('mcp-connection-not-found', (string) $response->getContent(), $id);
        }

        self::assertNull($this->causeOf($held));
    }

    /** Révoquer l'une laisse l'autre (même compte, autre client) active. */
    public function testTheOtherConnectionsAreLeftAlone(): void
    {
        $first = $this->holdMcpConnection();
        $second = $this->holdAnotherMcpConnection();
        $this->signInAs(self::TRUSTED_USERNAME, TestCredentials::superPassword());

        $response = $this->revoke($first->connectionId);

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        self::assertSame('person', $this->causeOf($first));
        self::assertNull($this->causeOf($second));
        self::assertNull($this->revokedAtOf($second));
    }

    /** Une requête à cookie : la garde CSRF double-submit s'applique, rien n'est révoqué sans l'en-tête. */
    public function testWithoutTheCsrfHeaderItIsForbidden(): void
    {
        $held = $this->holdMcpConnection();
        $this->signInAs(self::TRUSTED_USERNAME, TestCredentials::superPassword());

        $response = $this->revoke($held->connectionId, withCsrf: false);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        self::assertNull($this->causeOf($held));
        self::assertSame([], self::securityAuditEvents(self::REVOKED_EVENT));
    }

    public function testRevokingAsTheBaseTierIsForbidden(): void
    {
        $held = $this->holdMcpConnection();
        $this->client->request('POST', '/api/account/base-access', server: ['HTTP_X_REQUESTED_WITH' => 'fetch']);
        self::assertResponseIsSuccessful();

        $response = $this->revoke($held->connectionId);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        self::assertNull($this->causeOf($held));
    }

    /**
     * Une panne du journal de sécurité, **après** l'écriture, ne change pas la
     * réponse (la règle de #725) : 204, la connexion est révoquée, et la
     * panne sort en `critical` sur le canal principal — la classe seule.
     */
    public function testAnAuditFailureLeavesThe204(): void
    {
        $held = $this->holdMcpConnection();
        $this->signInAs(self::TRUSTED_USERNAME, TestCredentials::superPassword());
        $xsrf = $this->xsrfToken();
        $failing = self::createStub(SecurityAuditLoggerInterface::class);
        $failing->method('mcpConnectionRevoked')->willThrowException(new RuntimeException('Journal de sécurité indisponible'));
        // Le conteneur est neuf après un redémarrage du noyau : le service peut être remplacé.
        $this->client->getKernel()->shutdown();
        $this->client->getKernel()->boot();
        $this->client->disableReboot();
        $this->client->getContainer()->set(SecurityAuditLoggerInterface::class, $failing);

        $response = $this->revoke($held->connectionId, xsrf: $xsrf);

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        self::assertSame('person', $this->causeOf($held));
        $failures = array_values(array_filter(
            self::allChannelsLogRecordsOf('app'),
            static fn(LogRecord $record): bool => self::NOT_AUDITED === $record->message,
        ));
        self::assertCount(1, $failures);
        self::assertSame(Level::Critical, $failures[0]->level);
        self::assertSame(['exceptionClass' => RuntimeException::class], $failures[0]->context);
    }

    /** Un DELETE de la personne connectée ; la garde CSRF double-submit lit l'en-tête `X-XSRF-TOKEN`. */
    private function revoke(string $connectionId, bool $withCsrf = true, ?string $xsrf = null): Response
    {
        $server = ['HTTP_ACCEPT' => 'application/json'];
        if ($withCsrf) {
            $server['HTTP_X_XSRF_TOKEN'] = $xsrf ?? $this->xsrfToken();
        }

        $this->client->request('DELETE', self::PATH.'/'.$connectionId, server: $server);

        return $this->client->getResponse();
    }

    /**
     * @return array<string, mixed>
     */
    private function problemBody(Response $response): array
    {
        $body = $this->json($response);
        // La trace de débogage dépend de la ligne qui a levé, pas de la réponse.
        unset($body['trace']);

        return $body;
    }

    private function causeOf(HeldMcpConnection $held): ?string
    {
        $cause = $this->mcpRows()->fetchOne('SELECT revocation_cause FROM mcp_connection WHERE id = :id', ['id' => $held->connectionId]);
        self::assertTrue(false === $cause || null === $cause || \is_string($cause));

        return false === $cause ? null : $cause;
    }

    private function revokedAtOf(HeldMcpConnection $held): ?string
    {
        $at = $this->mcpRows()->fetchOne('SELECT revoked_at FROM mcp_connection WHERE id = :id', ['id' => $held->connectionId]);
        self::assertTrue(false === $at || null === $at || \is_string($at));

        return false === $at ? null : $at;
    }

    /** `tools/call get_nominative_corpus` dans la session, porté par le jeton de la connexion. */
    private function callNominativeCorpus(string $sessionId, HeldMcpConnection $held): void
    {
        $this->postMcp($this->client, $sessionId, [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'get_nominative_corpus', 'arguments' => ['locale' => 'en']],
        ], self::bearer($held->accessToken));
    }

    /** Une connexion de `other-trusted`, accordée par le vrai parcours ; le client MCP n'en garde pas les cookies. */
    private function holdConnectionOfAnotherAccount(): HeldMcpConnection
    {
        $this->loginAs(self::OTHER_TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);

        return $this->heldMcpConnectionOf($this->exchange($this->codeGrantedByTheSignedInAccount()));
    }

    /**
     * Ajoute `$count` connexions révoquées (cause `person`), au même compte et
     * au même client que `$template`, octroyées chacune une minute avant la
     * précédente à partir de maintenant : la n-ième est la n-ième plus récente.
     */
    private function insertTerminatedConnectionsLike(HeldMcpConnection $template, int $count): void
    {
        $inserted = $this->mcpRows()->executeStatement(
            "INSERT INTO mcp_connection (id, scope, granted_at, last_used_at, revoked_at, account_id, mcp_client_id, revocation_cause, code_exchanged_at)
             SELECT gen_random_uuid(), source.scope, now() - (n * INTERVAL '1 minute'), NULL, now(), source.account_id, source.mcp_client_id, 'person', now()
             FROM mcp_connection source CROSS JOIN generate_series(1, :count) AS n WHERE source.id = :id",
            ['count' => $count, 'id' => $template->connectionId],
        );
        self::assertSame($count, $inserted);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    /**
     * @return list<string> les révoquées de la base, la plus récemment octroyée d'abord
     */
    private function terminatedIdsNewestFirst(): array
    {
        $ids = $this->mcpRows()->fetchFirstColumn('SELECT id FROM mcp_connection WHERE revoked_at IS NOT NULL ORDER BY granted_at DESC, id DESC');

        return array_map(static fn(mixed $id): string => \is_string($id) ? $id : '', $ids);
    }

    private function listResponseAsTrusted(): Response
    {
        $this->signInAs(self::TRUSTED_USERNAME, TestCredentials::superPassword());
        $this->client->request('GET', self::PATH, server: ['HTTP_ACCEPT' => 'application/json']);

        return $this->client->getResponse();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listAsTrusted(): array
    {
        return $this->entriesOf($this->listResponseAsTrusted());
    }

    /**
     * @return array<string, mixed>
     */
    private function singleEntryAsTrusted(): array
    {
        $entries = $this->listAsTrusted();
        self::assertCount(1, $entries);

        return $entries[0];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function entriesOf(Response $response): array
    {
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        $connections = $this->json($response)['connections'] ?? null;
        self::assertIsList($connections);

        $entries = [];
        foreach ($connections as $entry) {
            self::assertIsArray($entry);
            /** @var array<string, mixed> $entry */
            $entries[] = $entry;
        }

        return $entries;
    }

    private function instant(string $atom): DateTimeImmutable
    {
        $instant = DateTimeImmutable::createFromFormat(DATE_ATOM, $atom);
        self::assertInstanceOf(DateTimeImmutable::class, $instant, $atom);

        return $instant;
    }

    /**
     * Les instructions SQL de la dernière requête, lues sur le canal
     * `doctrine` de la sonde (comme `McpBearerAuthenticatorTest`).
     *
     * @return list<string>
     */
    private function executedSql(): array
    {
        $queries = [];
        foreach (self::allChannelsLogRecordsOf('doctrine') as $record) {
            if (!str_starts_with($record->message, 'Executing ')) {
                continue;
            }

            $sql = $record->context['sql'] ?? $record->message;
            $queries[] = \is_string($sql) ? $sql : $record->message;
        }

        return $queries;
    }
}
