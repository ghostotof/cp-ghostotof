<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Application;

use App\Mcp\Connection\Application\McpConnectionStatus;
use App\Mcp\Connection\Application\OwnMcpConnectionState;
use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\ValueObject\ConnectionLifetimes;
use App\Mcp\Connection\Domain\ValueObject\RevocationCause;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Mcp\Connection\RegisteredMcpClient;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Clock\MockClock;

/**
 * Le calcul unique de l'état d'une connexion (#652, T12) : les bornes
 * reproduisent exactement celles qu'`OwnMcpConnections` appliquait avant
 * l'extraction.
 */
final class McpConnectionStatusTest extends TestCase
{
    private const string GRANTED = '2026-11-01T10:00:00+01:00';

    /**
     * @return iterable<string, array{bool, ?RevocationCause, string, OwnMcpConnectionState}>
     */
    public static function states(): iterable
    {
        // Échéances : inactivité P30D (code échangé, soit le 2026-12-01 à 10:00), ou PT1H tant que le code n'est pas échangé (11:00).
        yield 'active avant l’échéance' => [true, null, '2026-12-01T09:59:59+01:00', OwnMcpConnectionState::Active];
        yield 'expirée à l’échéance exacte' => [true, null, '2026-12-01T10:00:00+01:00', OwnMcpConnectionState::Expired];
        yield 'révoquée avant l’échéance' => [true, RevocationCause::Person, '2026-11-01T10:30:00+01:00', OwnMcpConnectionState::Revoked];
        yield 'révoquée puis échue reste révoquée' => [true, RevocationCause::Person, '2026-12-02T10:00:00+01:00', OwnMcpConnectionState::Revoked];
        yield 'cause expirée' => [true, RevocationCause::Expired, '2026-11-01T10:30:00+01:00', OwnMcpConnectionState::Expired];
        yield 'code jamais échangé, échéance de l’octroi abandonné' => [false, null, '2026-11-01T11:00:00+01:00', OwnMcpConnectionState::Expired];
        yield 'code jamais échangé, avant l’échéance' => [false, null, '2026-11-01T10:59:59+01:00', OwnMcpConnectionState::Active];
    }

    #[DataProvider('states')]
    public function testStateOfFollowsTheSingleRule(bool $exchanged, ?RevocationCause $cause, string $now, OwnMcpConnectionState $expected): void
    {
        $granted = new DateTimeImmutable(self::GRANTED);
        $connection = McpConnection::grant(
            new CpgUser('jane', 'hashed-password'),
            RegisteredMcpClient::of('client-1', 'Client de test', ['https://client.example.test/callback']),
            'nominative:read',
            $granted,
        );
        if ($exchanged) {
            $connection->recordCodeExchangeAt($granted);
        }
        if (null !== $cause) {
            // Le modèle n'a aucun mutateur de révocation (le dépôt seul l'écrit) : on pose les deux champs.
            new ReflectionProperty(McpConnection::class, 'revokedAt')->setValue($connection, $granted->modify('+1 minute'));
            new ReflectionProperty(McpConnection::class, 'revocationCause')->setValue($connection, $cause);
        }

        $status = $this->connectionStatus();

        self::assertSame($expected, $status->stateOf($connection, new DateTimeImmutable($now), $status->expiresAt($connection)));
    }

    /**
     * L'état se juge sur l'échéance reçue, jamais sur un recalcul : une seule
     * évaluation par ligne (#650, R5a ; #652, T12).
     */
    public function testStateOfJudgesOnTheReceivedDeadlineNotOnARecomputation(): void
    {
        $granted = new DateTimeImmutable(self::GRANTED);
        $connection = McpConnection::grant(
            new CpgUser('jane', 'hashed-password'),
            RegisteredMcpClient::of('client-1', 'Client de test', ['https://client.example.test/callback']),
            'nominative:read',
            $granted,
        );
        $connection->recordCodeExchangeAt($granted);
        $status = $this->connectionStatus();
        $now = new DateTimeImmutable('2026-11-15T10:00:00+01:00');

        // La vraie échéance (2026-12-01) est postérieure à $now : recalculée, la connexion serait active.
        self::assertGreaterThan($now, $status->expiresAt($connection));
        self::assertSame(
            OwnMcpConnectionState::Expired,
            $status->stateOf($connection, $now, $now->modify('-1 second')),
        );
    }

    public function testExpiresAtIsTheConnectionDeadline(): void
    {
        $granted = new DateTimeImmutable(self::GRANTED);
        $connection = McpConnection::grant(
            new CpgUser('jane', 'hashed-password'),
            RegisteredMcpClient::of('client-1', 'Client de test', ['https://client.example.test/callback']),
            'nominative:read',
            $granted,
        );
        $connection->recordCodeExchangeAt($granted);

        self::assertEquals(
            $connection->deadline($granted->getTimezone(), $this->lifetimes()),
            $this->connectionStatus()->expiresAt($connection),
        );
    }

    private function lifetimes(): ConnectionLifetimes
    {
        return ConnectionLifetimes::fromConfiguration('PT10M', 'PT1H', 'P30D', 'P30D', 'P90D', 'PT1H');
    }

    private function connectionStatus(): McpConnectionStatus
    {
        return new McpConnectionStatus($this->lifetimes(), new MockClock(new DateTimeImmutable(self::GRANTED)));
    }
}
