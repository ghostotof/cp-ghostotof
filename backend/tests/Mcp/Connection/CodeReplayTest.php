<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection;

use App\Tests\Mcp\Support\HeldMcpConnection;
use App\Tests\Mcp\Support\HoldsAnMcpConnection;
use App\Tests\Support\ReadsSecurityAuditLog;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le rejeu d'un code d'autorisation (#649, spec 0007 phase 2, OAuth 2.1
 * §4.1.2), de bout en bout par HTTP : un code s'échange une fois, et son
 * second échange — l'un après l'autre — révoque la connexion qu'il a ouverte,
 * cause `code-replay`, avec les jetons qu'il a émis.
 *
 * Le jugement se fait au point de jeton, sous le verrou de la ligne de la
 * connexion (`AuthCodeRepository::isAuthCodeRevoked()`), et la révocation
 * d'une connexion compromise s'écrit **après** la transaction de league,
 * annulée : c'est ce que ces tests lisent en base et dans le journal de
 * sécurité. Le journal lu est celui de la **dernière requête servie** (les
 * loggers sont remis à zéro entre deux requêtes, `disableReboot()` ou non —
 * mesuré) : la ligne d'audit se lit sur la requête qui l'écrit, et chaque
 * refus qui suit est lu à son tour pour n'en écrire aucune — une seconde
 * ligne ne passerait pas inaperçue.
 *
 * Pas de cas pour un code rejoué **après** ses dix minutes : league juge
 * l'échéance sur la charge chiffrée (`expire_time`, contre `time()`) avant
 * de consulter le dépôt, si bien qu'un tel rejeu est un simple
 * `invalid_grant` sans révocation, et qu'aucune `MockClock` ne l'atteint.
 * Limite acceptée, écrite dans la règle : au-delà de dix minutes, les jetons
 * issus du code vivent déjà sous rotation.
 */
final class CodeReplayTest extends WebTestCase
{
    use HoldsAnMcpConnection;
    use ReadsSecurityAuditLog;

    private const string REVOKED_EVENT = 'mcp-connection-revoked';

    /** Le refus de league pour un code révoqué, mot pour mot. */
    private const string REPLAY_HINT = 'Authorization code has been revoked';

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
     * Un code déjà échangé qui revient ne peut être qu'une fuite (la
     * redirection interceptée) — ou un client qui rejoue une réponse perdue :
     * les deux se ressemblent trait pour trait. La connexion est révoquée,
     * cause `code-replay`, et les jetons issus du code ne valent plus rien :
     * le rafraîchissement reçu au premier échange est refusé sans qu'une
     * ligne de jeton soit écrite — la seule issue est un nouveau consentement.
     *
     * Le refus reste celui de league, mot pour mot (« Authorization code has
     * been revoked ») : un attaquant n'apprend rien de plus qu'avant. La
     * ligne d'audit désigne le compte par son UUID, la connexion, le client
     * public et la cause — jamais le code ni un jeton.
     */
    public function testExchangingACodeTwiceRevokesTheConnection(): void
    {
        $code = $this->grantedCode();
        $issued = $this->exchange($code);
        $held = $this->heldMcpConnectionOf($issued);
        $refreshToken = $this->json($issued)['refresh_token'] ?? null;
        self::assertIsString($refreshToken);
        $this->assertConnectionActive($held);

        $replay = $this->exchange($code);

        $this->assertOAuthError($replay, Response::HTTP_BAD_REQUEST, 'invalid_grant');
        self::assertSame(self::REPLAY_HINT, $this->json($replay)['hint'] ?? null);
        self::assertSame('code-replay', $this->revocationCauseOf($held));
        $event = self::singleSecurityAuditEvent(self::REVOKED_EVENT);
        self::assertSame($held->accountId, $event['userId'] ?? null);
        self::assertSame($held->connectionId, $event['connectionId'] ?? null);
        self::assertSame(self::CLIENT_ID, $event['clientId'] ?? null);
        self::assertSame('code-replay', $event['cause'] ?? null);
        $context = json_encode($event, \JSON_THROW_ON_ERROR);
        foreach ([$code, $held->accessToken, $refreshToken] as $secret) {
            self::assertStringNotContainsString($secret, $context);
        }

        // Les jetons issus du code meurent avec la connexion : rien d'émis,
        // rien d'écrit, et ce refus-là n'audite rien de plus.
        $tokensBefore = $this->tokenRowCounts();
        $this->assertOAuthError($this->refresh($refreshToken), Response::HTTP_BAD_REQUEST, 'invalid_grant');
        self::assertSame($tokensBefore, $this->tokenRowCounts());
        self::assertSame([], self::securityAuditEvents(self::REVOKED_EVENT));
    }

    /**
     * Une connexion révoquée l'est une fois : un troisième échange du même
     * code est refusé comme le second, mais la révocation garde son instant
     * et sa cause, et le journal n'en reçoit aucune ligne — le dépôt tient
     * pour mort tout code d'une connexion révoquée, sans seconde révocation
     * ni audit.
     */
    public function testAReplayOnARevokedConnectionWritesNoSecondLine(): void
    {
        $code = $this->grantedCode();
        $held = $this->heldMcpConnectionOf($this->exchange($code));
        $this->assertOAuthError($this->exchange($code), Response::HTTP_BAD_REQUEST, 'invalid_grant');
        $revocation = $this->revocationOf($held);
        self::assertSame('code-replay', $revocation['cause']);
        self::assertCount(1, self::securityAuditEvents(self::REVOKED_EVENT));

        $third = $this->exchange($code);

        $this->assertOAuthError($third, Response::HTTP_BAD_REQUEST, 'invalid_grant');
        self::assertSame(self::REPLAY_HINT, $this->json($third)['hint'] ?? null);
        self::assertSame($revocation, $this->revocationOf($held));
        self::assertSame([], self::securityAuditEvents(self::REVOKED_EVENT));
    }

    /**
     * Un code présenté d'abord par un autre client enregistré est refusé par
     * league (« not issued to this client ») sans rien consommer : le dépôt,
     * consulté avant ce contrôle, n'écrit rien au premier jugement — le code
     * reste échangeable par son vrai client, la connexion intacte, le journal
     * vide.
     */
    public function testACodePresentedFirstByAnotherClientConsumesNothing(): void
    {
        $code = $this->grantedCode();

        $this->assertOAuthError($this->exchange($code, ['client_id' => self::OTHER_CLIENT_ID]), Response::HTTP_BAD_REQUEST, 'invalid_request');

        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_authorization_code WHERE revoked_at IS NOT NULL'));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_connection WHERE revoked_at IS NOT NULL'));
        self::assertSame([], self::securityAuditEvents(self::REVOKED_EVENT));
        $held = $this->heldMcpConnectionOf($this->exchange($code));
        $this->assertConnectionActive($held);
    }

    /**
     * League juge la révocation du code **avant** de le lier au client
     * authentifié (l'inverse du rafraîchissement, où le lien précède le
     * dépôt) : un code déjà échangé, rejoué sous le `client_id` d'un autre
     * client enregistré, révoque la connexion de son vrai client aussi.
     * Accepté, épinglé ici : le `client_id` d'un client public est public
     * (il figure dans la demande d'autorisation), quiconque rejoue un code
     * peut nommer le bon — un code qui revient a fuité, quel que soit le nom
     * sous lequel il revient — et le refus est celui que league rendait déjà
     * avant la révocation, mot pour mot.
     */
    public function testAReplayUnderAnotherClientRevokesTheConnectionToo(): void
    {
        $code = $this->grantedCode();
        $held = $this->heldMcpConnectionOf($this->exchange($code));

        $replay = $this->exchange($code, ['client_id' => self::OTHER_CLIENT_ID]);

        $this->assertOAuthError($replay, Response::HTTP_BAD_REQUEST, 'invalid_grant');
        self::assertSame(self::REPLAY_HINT, $this->json($replay)['hint'] ?? null);
        self::assertSame('code-replay', $this->revocationCauseOf($held));
        $event = self::singleSecurityAuditEvent(self::REVOKED_EVENT);
        self::assertSame(self::CLIENT_ID, $event['clientId'] ?? null, 'La ligne nomme le client de la connexion, pas celui qui rejoue.');
    }

    private function assertConnectionActive(HeldMcpConnection $held): void
    {
        self::assertNull($this->revocationCauseOf($held));
    }

    private function revocationCauseOf(HeldMcpConnection $held): ?string
    {
        return $this->revocationOf($held)['cause'];
    }

    /**
     * La révocation de la connexion telle qu'en base : sa cause et son instant
     * (époque Unix), `null` l'une et l'autre tant qu'elle est active.
     *
     * @return array{cause: ?string, revokedAt: ?int}
     */
    private function revocationOf(HeldMcpConnection $held): array
    {
        $row = $this->mcpRows()->fetchAssociative(
            'SELECT revocation_cause, EXTRACT(EPOCH FROM revoked_at)::bigint AS revoked_at FROM mcp_connection WHERE id = :id',
            ['id' => $held->connectionId],
        );
        self::assertIsArray($row);
        $cause = $row['revocation_cause'];
        $revokedAt = $row['revoked_at'];
        self::assertTrue(null === $cause || \is_string($cause));
        self::assertTrue(null === $revokedAt || \is_int($revokedAt));

        return ['cause' => $cause, 'revokedAt' => $revokedAt];
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
