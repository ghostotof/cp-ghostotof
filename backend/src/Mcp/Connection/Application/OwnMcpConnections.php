<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\ConnectionLifetimes;
use App\Mcp\Connection\Domain\ValueObject\RevocationCause;
use App\Shared\Domain\Security\AccountReference;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * « Mes connexions MCP » (#650), la lecture : une requête (les connexions du
 * compte avec leur client), l'état et l'échéance jugés à l'instant de
 * l'horloge — Paris, comme partout dans la tranche.
 *
 * **L'échéance affichée est celle que l'on juge** : {@see McpConnection::deadline()},
 * dont {@see McpConnection::isExpiredAt()} est la comparaison. Deux calculs
 * auraient pu se séparer ; il n'y en a qu'un.
 */
final readonly class OwnMcpConnections implements OwnMcpConnectionsInterface
{
    public function __construct(
        private McpConnectionRepositoryInterface $connections,
        private ClockInterface $clock,
        private ConnectionLifetimes $lifetimes,
    ) {
    }

    public function of(AccountReference $account): array
    {
        $now = $this->clock->now();

        return array_map(
            fn(McpConnection $connection): OwnMcpConnectionView => $this->viewOf($connection, $now),
            $this->connections->findAllOfAccount(Uuid::fromString($account->accountId())),
        );
    }

    private function viewOf(McpConnection $connection, DateTimeImmutable $now): OwnMcpConnectionView
    {
        $zone = $now->getTimezone();

        return new OwnMcpConnectionView(
            $connection->getId(),
            $connection->client()->getClientId(),
            $connection->client()->getName(),
            $connection->grantedAt()->setTimezone($zone),
            $connection->lastUsedAt()?->setTimezone($zone),
            $connection->deadline($zone, $this->lifetimes),
            $this->stateOf($connection, $now),
            $connection->revokedAt()?->setTimezone($zone),
            $connection->revocationCause(),
        );
    }

    /**
     * La règle d'état, une seule :
     *
     * - `revoked` : `revoked_at` posé **et** une cause autre que `expired` —
     *   la personne, le client, le serveur ont coupé la connexion ;
     * - `expired` : la cause est `expired` (la purge a marqué l'échéance), **ou**
     *   l'échéance est passée sans que la purge ait encore tourné
     *   ({@see McpConnection::isExpiredAt()}) — jamais `revoked_at` seul, qui ne
     *   dit pas pourquoi (commentaire de #649) ;
     * - `active` sinon.
     *
     * Une connexion révoquée puis échue reste `revoked` : sa cause est ce qui
     * l'a coupée, et l'échéance n'y change rien.
     */
    private function stateOf(McpConnection $connection, DateTimeImmutable $now): OwnMcpConnectionState
    {
        $cause = $connection->revocationCause();

        if ($connection->isRevoked() && RevocationCause::Expired !== $cause) {
            return OwnMcpConnectionState::Revoked;
        }

        if (RevocationCause::Expired === $cause || $connection->isExpiredAt($now, $this->lifetimes)) {
            return OwnMcpConnectionState::Expired;
        }

        return OwnMcpConnectionState::Active;
    }
}
