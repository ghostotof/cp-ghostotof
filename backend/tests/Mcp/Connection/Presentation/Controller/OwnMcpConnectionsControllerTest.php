<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Presentation\Controller;

use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Mcp\Support\HeldMcpConnection;
use App\Tests\Mcp\Support\HoldsAnMcpConnection;
use App\Tests\Support\ReadsAllChannelsLog;
use App\Tests\Support\TestCredentials;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

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

    private const string PATH = '/api/me/mcp-connections';

    private const string OTHER_TRUSTED_USERNAME = 'other-trusted';

    /** Les clés exactes d'une entrée de la liste, dans l'ordre où l'API les écrit. */
    private const array ENTRY_KEYS = ['id', 'client', 'grantedAt', 'lastUsedAt', 'expiresAt', 'state', 'revokedAt', 'revocationCause'];

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
        self::assertSame(['connections'], array_keys($body));
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
     * Trois connexions, une requête de plus que l'authentification : la liste
     * lit les connexions et leur client en une instruction (jointure), jamais
     * une requête par connexion.
     */
    public function testTheListCostsOneQuery(): void
    {
        $this->holdMcpConnection();
        $this->holdAnotherMcpConnection();
        $this->signInAs(self::TRUSTED_USERNAME, TestCredentials::superPassword());
        $this->codeGrantedByTheSignedInAccount();

        $this->client->request('GET', self::PATH, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        self::assertCount(3, $this->entriesOf($this->client->getResponse()));
        $statements = array_values(array_filter($this->executedSql(), static fn(string $sql): bool => str_contains($sql, 'mcp_')));
        self::assertCount(1, $statements, implode("\n", $statements));
        self::assertStringContainsString('mcp_client', $statements[0], 'Le client est joint à la connexion.');
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

    /** Une connexion de `other-trusted`, accordée par le vrai parcours ; le client MCP n'en garde pas les cookies. */
    private function holdConnectionOfAnotherAccount(): HeldMcpConnection
    {
        $this->loginAs(self::OTHER_TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);

        return $this->heldMcpConnectionOf($this->exchange($this->codeGrantedByTheSignedInAccount()));
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
