<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Domain\Entity;

use App\Mcp\Connection\Domain\Entity\McpAccessToken;
use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\ValueObject\BearerRefusal;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Mcp\Connection\RegisteredMcpClient;
use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Ce qui refuse un jeton d'accès à un instant donné (#648) : le jeton lui-même
 * (révoqué, expiré, d'une autre audience), puis sa connexion (révoquée,
 * expirée après 30 jours sans usage ou 90 jours après l'octroi).
 *
 * Les instants portent un décalage fixe (`+02:00`) : l'arithmétique des jours
 * n'y croise aucun changement d'heure, chaque borne tombe à la seconde près.
 */
final class McpAccessTokenRefusalTest extends TestCase
{
    private const string AUDIENCE = 'https://example.test/api/mcp';

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-24T12:00:00+02:00');
    }

    public function testAFreshTokenOfALiveConnectionIsAccepted(): void
    {
        $token = $this->token($this->connectionGrantedAt($this->now->sub(new DateInterval('PT1H'))));

        self::assertNull($token->refusalAt($this->now, self::AUDIENCE));
    }

    /**
     * L'échéance est exclusive : à `expiresAt` exactement, le jeton est mort.
     */
    public function testATokenIsExpiredAtItsExactExpiry(): void
    {
        $token = new McpAccessToken($this->liveConnection(), $this->hash(), self::AUDIENCE, $this->now);

        self::assertSame(BearerRefusal::TokenExpired, $token->refusalAt($this->now, self::AUDIENCE));
        self::assertNull($token->refusalAt($this->now->sub(new DateInterval('PT1S')), self::AUDIENCE));
    }

    public function testARevokedTokenIsRefusedBeforeItsExpiry(): void
    {
        $token = $this->token($this->liveConnection());
        $token->revoke($this->now->sub(new DateInterval('PT1M')));

        self::assertSame(BearerRefusal::TokenRevoked, $token->refusalAt($this->now, self::AUDIENCE));
    }

    /**
     * L'audience se compare octet pour octet : une barre oblique finale de
     * plus est une autre ressource (RFC 8707).
     */
    public function testAnotherAudienceIsRefused(): void
    {
        $token = $this->token($this->liveConnection());

        self::assertSame(BearerRefusal::ForeignAudience, $token->refusalAt($this->now, self::AUDIENCE.'/'));
    }

    public function testARevokedConnectionRefusesItsLiveToken(): void
    {
        $connection = $this->liveConnection();
        $token = $this->token($connection);
        $this->setConnectionInstant($connection, 'revokedAt', $this->now->sub(new DateInterval('PT1M')));

        self::assertSame(BearerRefusal::ConnectionRevoked, $token->refusalAt($this->now, self::AUDIENCE));
    }

    /**
     * Sans usage écrit (`lastUsedAt` nul, l'état de toute connexion avant
     * #649), le délai d'inactivité court depuis l'octroi.
     */
    public function testAConnectionUnusedFor30DaysIsExpired(): void
    {
        $thirtyDaysAgo = $this->now->sub(new DateInterval('P30D'));

        $expired = $this->token($this->connectionGrantedAt($thirtyDaysAgo));
        $alive = $this->token($this->connectionGrantedAt($thirtyDaysAgo->add(new DateInterval('PT1S'))));

        self::assertSame(BearerRefusal::ConnectionExpired, $expired->refusalAt($this->now, self::AUDIENCE));
        self::assertNull($alive->refusalAt($this->now, self::AUDIENCE));
    }

    public function testLastUseRestartsTheIdleDelay(): void
    {
        $connection = $this->connectionGrantedAt($this->now->sub(new DateInterval('P60D')));
        $this->setConnectionInstant($connection, 'lastUsedAt', $this->now->sub(new DateInterval('P1D')));

        self::assertNull($this->token($connection)->refusalAt($this->now, self::AUDIENCE));
    }

    /**
     * La durée maximale ne dépend pas de l'usage : 90 jours après l'octroi, la
     * connexion est morte même utilisée l'heure d'avant.
     */
    public function testAConnectionIsExpired90DaysAfterItsGrantWhateverItsUse(): void
    {
        $ninetyDaysAgo = $this->now->sub(new DateInterval('P90D'));
        $anHourAgo = $this->now->sub(new DateInterval('PT1H'));

        $expired = $this->connectionGrantedAt($ninetyDaysAgo);
        $this->setConnectionInstant($expired, 'lastUsedAt', $anHourAgo);
        $alive = $this->connectionGrantedAt($ninetyDaysAgo->add(new DateInterval('PT1S')));
        $this->setConnectionInstant($alive, 'lastUsedAt', $anHourAgo);

        self::assertSame(BearerRefusal::ConnectionExpired, $this->token($expired)->refusalAt($this->now, self::AUDIENCE));
        self::assertNull($this->token($alive)->refusalAt($this->now, self::AUDIENCE));
    }

    /**
     * Les causes se jugent dans un ordre fixe — jeton révoqué, jeton expiré,
     * audience, connexion révoquée, connexion expirée — : un jeton qui les
     * cumule toutes rend la première, et chacune se découvre quand on lève
     * celle d'avant.
     */
    public function testTheCausesAreJudgedInTheirOrder(): void
    {
        $connection = $this->connectionGrantedAt($this->now->sub(new DateInterval('P91D')));
        $this->setConnectionInstant($connection, 'revokedAt', $this->now->sub(new DateInterval('PT1M')));
        $token = new McpAccessToken($connection, $this->hash(), self::AUDIENCE, $this->now);
        $token->revoke($this->now->sub(new DateInterval('PT1M')));
        $foreign = self::AUDIENCE.'/';

        self::assertSame(BearerRefusal::TokenRevoked, $token->refusalAt($this->now, $foreign));

        $unrevoked = new McpAccessToken($connection, $this->hash(), self::AUDIENCE, $this->now);
        self::assertSame(BearerRefusal::TokenExpired, $unrevoked->refusalAt($this->now, $foreign));

        $live = $this->token($connection);
        self::assertSame(BearerRefusal::ForeignAudience, $live->refusalAt($this->now, $foreign));
        self::assertSame(BearerRefusal::ConnectionRevoked, $live->refusalAt($this->now, self::AUDIENCE));

        $this->setConnectionInstant($connection, 'revokedAt', null);
        self::assertSame(BearerRefusal::ConnectionExpired, $live->refusalAt($this->now, self::AUDIENCE));
    }

    /** Un jeton d'accès de l'audience attendue, valide une heure encore. */
    private function token(McpConnection $connection): McpAccessToken
    {
        return new McpAccessToken($connection, $this->hash(), self::AUDIENCE, $this->now->add(new DateInterval('PT1H')));
    }

    private function liveConnection(): McpConnection
    {
        return $this->connectionGrantedAt($this->now->sub(new DateInterval('P1D')));
    }

    private function connectionGrantedAt(DateTimeImmutable $grantedAt): McpConnection
    {
        return McpConnection::grant(
            new CpgUser('jane', 'hashed-password'),
            RegisteredMcpClient::of('https://client.example.test/metadata.json', 'Client de test', []),
            McpConnection::SCOPE_NOMINATIVE_READ,
            $grantedAt,
        );
    }

    /**
     * Le dernier usage et la révocation d'une connexion n'ont pas encore de
     * mutateur (#649, #650) : posés par réflexion, comme la base les rendrait.
     */
    private function setConnectionInstant(McpConnection $connection, string $property, ?DateTimeImmutable $at): void
    {
        new ReflectionProperty(McpConnection::class, $property)->setValue($connection, $at);
    }

    private function hash(): string
    {
        return hash('sha256', random_bytes(16));
    }
}
