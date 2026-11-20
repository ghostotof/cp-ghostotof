<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\ConnectionLifetimes;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * La lecture des connexions MCP de l'administration (#652), en trois requêtes
 * (vivantes, révoquées, abandonnées), chacune avec son client et son compte
 * joints, **lues dans un même instantané** ({@see ReadSnapshotInterface}) : une
 * connexion qui change de moitié entre deux requêtes ne disparaît pas et ne
 * s'y trouve pas deux fois.
 *
 * Pourquoi deux moitiés, puis une fusion : les vivantes sont toutes montrées,
 * les terminées sont bornées à {@see self::TERMINATED_LIMIT}. Les terminées
 * viennent de deux lectures (les révoquées ; les accords abandonnés, que la
 * purge n'a pas encore marqués), dont chacune est bornée par son propre tri
 * SQL : aucune requête ne peut dire laquelle des deux a terminé le plus
 * récemment. La fusion le décide ici, sur l'instant de fin réel de chaque
 * ligne — `revokedAt` pour une révoquée, octroi + délai du code non échangé
 * ({@see ConnectionLifetimes::unexchangedDeadline()}) pour une abandonnée —, et
 * garde les {@see self::TERMINATED_LIMIT} dernières. L'ordre d'**affichage**
 * est ensuite celui de l'octroi : l'instant de fin décide *quelles* terminées
 * on garde, pas l'ordre où on les montre.
 *
 * Un accord abandonné est un octroi dont le code n'a jamais été échangé : la
 * supplantation n'a lieu qu'à l'échange du code, et l'expiration d'une heure
 * n'est marquée en base que par la purge. Jusqu'à son passage, il n'est pas
 * révoqué ; il est lu à part et rangé parmi les terminées, état `Expired`,
 * sans `revokedAt` ni cause.
 *
 * L'état et l'échéance sont jugés à l'instant de l'horloge par
 * {@see McpConnectionStatus}, une seule évaluation d'échéance par ligne.
 */
final readonly class AdministeredMcpConnections implements AdministeredMcpConnectionsInterface
{
    /**
     * Combien de connexions terminées la liste montre, au plus : le seul
     * endroit où ce nombre est écrit.
     */
    public const int TERMINATED_LIMIT = 200;

    public function __construct(
        private McpConnectionRepositoryInterface $connections,
        private ClockInterface $clock,
        private McpConnectionStatus $status,
        private ConnectionLifetimes $lifetimes,
        private ReadSnapshotInterface $snapshot,
    ) {
    }

    public function list(): array
    {
        $now = $this->clock->now();
        $threshold = $this->lifetimes->abandonedBefore($now);

        $reads = $this->snapshot->read(fn (): array => [
            'alive' => $this->connections->findNotRevokedForAdministration($threshold),
            'revoked' => $this->connections->findTerminatedForAdministration(self::TERMINATED_LIMIT),
            'abandoned' => $this->connections->findAbandonedForAdministration($threshold, self::TERMINATED_LIMIT),
        ]);

        // Les deux moitiés terminées se mêlent à leur fin réelle, la plus récente d'abord.
        $terminated = [...$reads['revoked'], ...$reads['abandoned']];
        usort($terminated, $this->latestEndFirst(...));
        $connections = [...$reads['alive'], ...\array_slice($terminated, 0, self::TERMINATED_LIMIT)];
        usort($connections, $this->newestGrantFirst(...));

        return array_map(fn (McpConnection $connection): AdministeredMcpConnectionView => $this->viewOf($connection, $now), $connections);
    }

    /** L'instant où la connexion a pris fin : sa révocation, ou la fin du délai du code non échangé. */
    private function endOf(McpConnection $connection): DateTimeImmutable
    {
        return $connection->revokedAt() ?? $this->lifetimes->unexchangedDeadline($connection->grantedAt());
    }

    /** Fin décroissante, l'identifiant décroissant départageant deux fins égales (instants comparés, jamais leur texte). */
    private function latestEndFirst(McpConnection $a, McpConnection $b): int
    {
        $byEnd = $this->endOf($b) <=> $this->endOf($a);

        return 0 !== $byEnd ? $byEnd : strcmp($b->getId()->toRfc4122(), $a->getId()->toRfc4122());
    }

    /** Octroi décroissant, l'identifiant décroissant départageant deux octrois égaux. */
    private function newestGrantFirst(McpConnection $a, McpConnection $b): int
    {
        $byGrant = $b->grantedAt() <=> $a->grantedAt();

        return 0 !== $byGrant ? $byGrant : strcmp($b->getId()->toRfc4122(), $a->getId()->toRfc4122());
    }

    private function viewOf(McpConnection $connection, DateTimeImmutable $now): AdministeredMcpConnectionView
    {
        $zone = $now->getTimezone();
        // Une seule évaluation de l'échéance par ligne : celle qui est rendue
        // est celle qui juge l'état.
        $deadline = $this->status->expiresAt($connection);
        $state = $this->status->stateOf($connection, $now, $deadline);

        return new AdministeredMcpConnectionView(
            $connection->getId(),
            $connection->account()->getUsername(),
            $connection->client()->getClientId(),
            $connection->client()->getName(),
            $connection->client()->registration(),
            $connection->grantedAt()->setTimezone($zone),
            null !== $connection->codeExchangedAt(),
            $connection->lastUsedAt()?->setTimezone($zone),
            $deadline,
            $state,
            $connection->revokedAt()?->setTimezone($zone),
            $connection->revocationCause()?->value,
        );
    }
}
