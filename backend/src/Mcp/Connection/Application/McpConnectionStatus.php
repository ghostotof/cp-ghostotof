<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\ValueObject\ConnectionLifetimes;
use App\Mcp\Connection\Domain\ValueObject\RevocationCause;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * L'état d'une connexion MCP et son échéance, **calculés à un seul endroit**
 * (#652, T12) : « Mes connexions MCP » et tout lecteur qui en aura besoin
 * partagent cette règle, jamais deux calculs qui pourraient se séparer.
 *
 * - `Revoked` : la connexion est révoquée pour une autre cause que l'expiration ;
 * - `Expired` : la cause est `Expired`, ou `$now` a atteint l'échéance (borne
 *   incluse : à la seconde exacte de l'échéance, la connexion est morte) ;
 * - `Active` : sinon.
 *
 * Une connexion révoquée puis échue reste `Revoked`. L'échéance est
 * {@see McpConnection::deadline()}, comptée dans le fuseau de l'horloge — le
 * même que celui de l'instant `$now` qu'elle rend.
 */
final readonly class McpConnectionStatus
{
    public function __construct(
        private ConnectionLifetimes $lifetimes,
        private ClockInterface $clock,
    ) {
    }

    /** L'échéance de la connexion, dans le fuseau de l'horloge. */
    public function expiresAt(McpConnection $connection): DateTimeImmutable
    {
        return $connection->deadline($this->clock->now()->getTimezone(), $this->lifetimes);
    }

    public function stateOf(McpConnection $connection, DateTimeImmutable $now): OwnMcpConnectionState
    {
        $cause = $connection->revocationCause();
        if ($connection->isRevoked() && RevocationCause::Expired !== $cause) {
            return OwnMcpConnectionState::Revoked;
        }

        if (RevocationCause::Expired === $cause || $now >= $this->expiresAt($connection)) {
            return OwnMcpConnectionState::Expired;
        }

        return OwnMcpConnectionState::Active;
    }
}
