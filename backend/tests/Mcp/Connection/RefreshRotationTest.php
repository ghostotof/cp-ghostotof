<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection;

use App\Tests\Mcp\Support\HeldMcpConnection;
use App\Tests\Mcp\Support\HoldsAnMcpConnection;
use App\Tests\Support\ReadsSecurityAuditLog;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * La rotation des jetons de rafraîchissement et ce qu'elle emporte (#649,
 * spec 0007 phase 2, OAuth 2.1 §4.3.1), de bout en bout par HTTP : chaque
 * rafraîchissement remplace le couple de jetons, un rafraîchissement déjà
 * tourné présenté de nouveau révoque la connexion, et un rafraîchissement
 * n'obtient rien d'une connexion expirée, d'un compte déchu ou sous un autre
 * client.
 *
 * Le jugement se fait au point de jeton, sous le verrou de la ligne de la
 * connexion (`RefreshTokenRepository::isRefreshTokenRevoked()`), et la
 * révocation d'une connexion compromise s'écrit **après** la transaction de
 * league, annulée : c'est ce que ces tests lisent en base et dans le journal
 * de sécurité. Les lignes sont altérées en SQL entre deux requêtes, le kernel
 * redémarrant entre elles.
 */
final class RefreshRotationTest extends WebTestCase
{
    use HoldsAnMcpConnection;
    use ReadsSecurityAuditLog;

    private const string REVOKED_EVENT = 'mcp-connection-revoked';

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
     * Le rafraîchissement remplace les deux jetons ; l'ancien rafraîchissement,
     * tourné, est refusé ensuite sous le refus de league pour un jeton
     * révoqué (`invalid_grant`, RFC 6749 §5.2).
     */
    public function testARefreshIssuesANewPairAndTheOldRefreshTokenIsRefusedAfterwards(): void
    {
        [$held, $firstRefreshToken] = $this->connect();

        $refreshed = $this->tokensOf($this->refresh($firstRefreshToken));

        self::assertNotSame($held->accessToken, $refreshed['access_token']);
        self::assertNotSame($firstRefreshToken, $refreshed['refresh_token']);
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM mcp_refresh_token WHERE revoked_at IS NULL'));
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM mcp_access_token WHERE revoked_at IS NULL'));

        $this->assertOAuthError($this->refresh($firstRefreshToken), Response::HTTP_BAD_REQUEST, 'invalid_grant');
    }

    /**
     * Un rafraîchissement déjà tourné, présenté de nouveau par son client sur
     * une connexion encore active, ne peut être qu'une fuite — ou un client
     * qui rejoue une réponse perdue (coupure réseau après le COMMIT du
     * premier rafraîchissement) : les deux se ressemblent trait pour trait,
     * et la rotation d'OAuth 2.1 accepte ce prix. La connexion est révoquée,
     * cause `refresh-token-reuse`, et son successeur ne vaut plus rien : la
     * personne devra se reconnecter. Documenté, jamais contourné.
     *
     * Le refus reste celui de league, mot pour mot (« Token has been
     * revoked ») : un attaquant n'apprend rien de plus qu'avant. La ligne
     * d'audit désigne le compte par son UUID, la connexion, le client public
     * et la cause — jamais un jeton.
     */
    public function testReusingARotatedRefreshTokenRevokesTheConnection(): void
    {
        [$held, $firstRefreshToken] = $this->connect();
        $refreshed = $this->tokensOf($this->refresh($firstRefreshToken));
        $this->assertConnectionActive($held);

        $reuse = $this->refresh($firstRefreshToken);

        $this->assertOAuthError($reuse, Response::HTTP_BAD_REQUEST, 'invalid_grant');
        self::assertSame('Token has been revoked', $this->json($reuse)['hint'] ?? null);
        self::assertSame('refresh-token-reuse', $this->revocationCauseOf($held));

        $event = self::singleSecurityAuditEvent(self::REVOKED_EVENT);
        self::assertSame($held->accountId, $event['userId'] ?? null);
        self::assertSame($held->connectionId, $event['connectionId'] ?? null);
        self::assertSame(self::CLIENT_ID, $event['clientId'] ?? null);
        self::assertSame('refresh-token-reuse', $event['cause'] ?? null);
        $context = json_encode($event, \JSON_THROW_ON_ERROR);
        foreach ([$held->accessToken, $firstRefreshToken, $refreshed['access_token'], $refreshed['refresh_token']] as $secret) {
            self::assertStringNotContainsString($secret, $context);
        }

        // Le successeur du jeton réutilisé meurt avec la connexion, et aucun
        // jeton nouveau n'est émis : la seule issue est un nouveau consentement.
        $tokensBefore = $this->tokenRowCounts();
        $this->assertOAuthError($this->refresh($refreshed['refresh_token']), Response::HTTP_BAD_REQUEST, 'invalid_grant');
        self::assertSame($tokensBefore, $this->tokenRowCounts());
    }

    /**
     * La réutilisation se juge avant l'échéance : un jeton tourné qui revient
     * sur une connexion expirée mais pas encore purgée est une réutilisation
     * aussi — révoquée sous sa cause, pas laissée à la purge comme une
     * simple expiration.
     */
    public function testAReusedTokenOnAnExpiredConnectionIsStillAReuse(): void
    {
        [$held, $firstRefreshToken] = $this->connect();
        $this->tokensOf($this->refresh($firstRefreshToken));
        $this->alterHeldConnection($held, "granted_at = granted_at - INTERVAL '30 days', last_used_at = NULL");

        $this->assertOAuthError($this->refresh($firstRefreshToken), Response::HTTP_BAD_REQUEST, 'invalid_grant');

        self::assertSame('refresh-token-reuse', $this->revocationCauseOf($held));
        self::assertSame('refresh-token-reuse', self::singleSecurityAuditEvent(self::REVOKED_EVENT)['cause'] ?? null);
    }

    /**
     * Un rafraîchissement présenté sous un autre `client_id` est refusé par
     * league avant que ce dépôt soit consulté (« Token is not linked to
     * client ») : la connexion de son vrai client reste intacte — sinon
     * quiconque connaît un jeton volé couperait la connexion d'autrui sans
     * pouvoir l'utiliser. Le vrai client rafraîchit ensuite comme si de rien.
     */
    public function testARefreshTokenPresentedByAnotherClientLeavesTheConnectionAlone(): void
    {
        [$held, $firstRefreshToken] = $this->connect();

        $this->assertOAuthError($this->refresh($firstRefreshToken, ['client_id' => self::OTHER_CLIENT_ID]), Response::HTTP_BAD_REQUEST, 'invalid_grant');

        $this->assertConnectionActive($held);
        self::assertSame([], self::securityAuditEvents(self::REVOKED_EVENT));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_refresh_token WHERE revoked_at IS NOT NULL'));
        $this->tokensOf($this->refresh($firstRefreshToken));
    }

    /**
     * Une connexion expirée par inactivité (octroi reculé de trente jours,
     * jamais utilisée) n'obtient aucun jeton neuf : le rafraîchissement est
     * refusé sans qu'une seule ligne de jeton soit écrite — la boucle
     * refus/rafraîchissement du report B1 de T9 n'accumule rien. La
     * connexion n'est pas révoquée pour autant : la purge la marquera.
     */
    public function testAnExpiredConnectionCannotRefresh(): void
    {
        [$held, $firstRefreshToken] = $this->connect();
        $this->alterHeldConnection($held, "granted_at = granted_at - INTERVAL '30 days'");

        $this->assertRefreshIssuesNothing($held, $firstRefreshToken);
    }

    /**
     * La durée maximale ne dépend pas de l'usage : octroyée il y a
     * quatre-vingt-dix jours et utilisée à l'instant, la connexion est morte.
     */
    public function testAConnectionPastItsMaximumCannotRefresh(): void
    {
        [$held, $firstRefreshToken] = $this->connect();
        $this->alterHeldConnection($held, "granted_at = granted_at - INTERVAL '90 days', last_used_at = now()");

        $this->assertRefreshIssuesNothing($held, $firstRefreshToken);
    }

    /**
     * Un compte qui a perdu le palier de confiance (rôles stockés réduits au
     * palier de base) ne rafraîchit plus : la connexion ne vaut que par le
     * palier de la personne, relu en base à chaque rafraîchissement comme à
     * chaque appel de `/api/mcp`.
     */
    public function testAnAccountNoLongerTrustedCannotRefresh(): void
    {
        [$held, $firstRefreshToken] = $this->connect();
        $this->rewriteHeldAccountRoles($held, ['ROLE_USER']);

        $this->assertRefreshIssuesNothing($held, $firstRefreshToken);
    }

    /**
     * Le dernier usage de la connexion s'écrit au rafraîchissement seulement,
     * et au plus une fois par heure écoulée (ADR 0006 D6 : jamais par appel de
     * `/api/mcp`) : nul après l'échange, posé par le premier rafraîchissement,
     * laissé tel quel par un rafraîchissement trente minutes plus tard,
     * reposé par un rafraîchissement soixante et une minutes plus tard.
     */
    public function testARefreshRecordsTheLastUseAtMostOncePerHour(): void
    {
        [$held, $firstRefreshToken] = $this->connect();
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM mcp_connection WHERE last_used_at IS NULL'), 'L\'échange ne note aucun usage.');

        $second = $this->tokensOf($this->refresh($firstRefreshToken));
        $firstUse = $this->lastUsedAtOf($held);
        self::assertNotNull($firstUse);
        self::assertEqualsWithDelta(time(), $firstUse, 5);

        $this->alterHeldConnection($held, "last_used_at = now() - INTERVAL '30 minutes'");
        $recentUse = $this->lastUsedAtOf($held);
        $third = $this->tokensOf($this->refresh($second['refresh_token']));
        self::assertSame($recentUse, $this->lastUsedAtOf($held), 'Un usage de moins d\'une heure ne se réécrit pas.');

        $this->alterHeldConnection($held, "last_used_at = now() - INTERVAL '61 minutes'");
        $this->tokensOf($this->refresh($third['refresh_token']));
        $lastUse = $this->lastUsedAtOf($held);
        self::assertNotNull($lastUse);
        self::assertEqualsWithDelta(time(), $lastUse, 5);
    }

    /**
     * Une connexion accordée par le parcours complet : ce qui la désigne, et
     * le jeton de rafraîchissement que le client a reçu avec son jeton d'accès.
     *
     * @return array{HeldMcpConnection, string}
     */
    private function connect(): array
    {
        $response = $this->exchange($this->grantedCode());
        $refreshToken = $this->json($response)['refresh_token'] ?? null;
        self::assertIsString($refreshToken);

        return [$this->heldMcpConnectionOf($response), $refreshToken];
    }

    /**
     * @return array{access_token: string, refresh_token: string}
     */
    private function tokensOf(Response $response): array
    {
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        $body = $this->json($response);
        $accessToken = $body['access_token'] ?? null;
        $refreshToken = $body['refresh_token'] ?? null;
        self::assertIsString($accessToken);
        self::assertIsString($refreshToken);

        return ['access_token' => $accessToken, 'refresh_token' => $refreshToken];
    }

    /**
     * Le rafraîchissement est refusé (`invalid_grant`), aucune ligne de jeton
     * n'est écrite ni révoquée, la connexion n'est pas révoquée et rien n'est
     * audité.
     */
    private function assertRefreshIssuesNothing(HeldMcpConnection $held, string $refreshToken): void
    {
        $tokensBefore = $this->tokenRowCounts();

        $this->assertOAuthError($this->refresh($refreshToken), Response::HTTP_BAD_REQUEST, 'invalid_grant');

        self::assertSame($tokensBefore, $this->tokenRowCounts());
        $this->assertConnectionActive($held);
        self::assertSame([], self::securityAuditEvents(self::REVOKED_EVENT));
    }

    private function assertConnectionActive(HeldMcpConnection $held): void
    {
        self::assertNull($this->revocationCauseOf($held));
    }

    private function revocationCauseOf(HeldMcpConnection $held): ?string
    {
        $cause = $this->mcpRows()->fetchOne('SELECT revocation_cause FROM mcp_connection WHERE id = :id', ['id' => $held->connectionId]);
        self::assertTrue(null === $cause || \is_string($cause));

        return $cause;
    }

    /** L'instant du dernier usage (époque Unix), ou `null`. */
    private function lastUsedAtOf(HeldMcpConnection $held): ?int
    {
        $epoch = $this->mcpRows()->fetchOne('SELECT EXTRACT(EPOCH FROM last_used_at)::bigint FROM mcp_connection WHERE id = :id', ['id' => $held->connectionId]);
        self::assertTrue(null === $epoch || \is_int($epoch));

        return $epoch;
    }

    /**
     * Le nombre de lignes de jetons, actives et révoquées : ce qu'un
     * rafraîchissement refusé ne doit pas changer.
     *
     * @return array<string, int>
     */
    private function tokenRowCounts(): array
    {
        return [
            'access' => $this->countRows('SELECT COUNT(*) FROM mcp_access_token'),
            'access révoqués' => $this->countRows('SELECT COUNT(*) FROM mcp_access_token WHERE revoked_at IS NOT NULL'),
            'refresh' => $this->countRows('SELECT COUNT(*) FROM mcp_refresh_token'),
            'refresh révoqués' => $this->countRows('SELECT COUNT(*) FROM mcp_refresh_token WHERE revoked_at IS NOT NULL'),
        ];
    }
}
