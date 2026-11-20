<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\ConnectionLifetimes;
use App\Mcp\Connection\Domain\ValueObject\RevocationCause;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

/**
 * La lecture des connexions MCP de l'administration (#652), **lue dans un même
 * instantané** ({@see ReadSnapshotInterface}) : une connexion qui change de
 * moitié entre deux requêtes ne disparaît pas et ne s'y trouve pas deux fois.
 *
 * Les vivantes sont toutes montrées ; les terminées sont bornées à
 * {@see self::TERMINATED_LIMIT} et **choisies par leur fin réelle**, pas par
 * l'instant où la base les a marquées : la purge pose `revoked_at` à son propre
 * passage sur une connexion échue, bien après son échéance (spec 0007, S4,
 * constat 1). Trois lectures, chacune exacte sur son critère :
 * - les terminées par une **autre cause** que l'expiration : la fin est
 *   `revoked_at`, triées par SQL ;
 * - les accords **jamais échangés** (abandonnés non marqués, ou marqués
 *   expirés) : la fin est l'octroi plus le délai du code non échangé
 *   ({@see ConnectionLifetimes::unexchangedDeadline()}), triés par octroi en SQL ;
 * - les **expirées échangées** : la fin est la plus proche des échéances
 *   d'inactivité et de plafond ({@see McpConnection::deadline()}), qu'on ne
 *   calcule **que** en PHP — jour de calendrier d'un changement d'heure, fin
 *   de mois : PostgreSQL et PHP divergent. SQL les rend par `revoked_at`
 *   décroissant, par pages à clé de reprise, et comme la fin réelle ne dépasse
 *   jamais `revoked_at`, la lecture s'arrête dès que le `revoked_at` d'une page
 *   passe **strictement** sous la {@see self::TERMINATED_LIMIT}-ième meilleure
 *   fin connue : aucune ligne suivante ne peut plus l'égaler.
 *
 * Les terminées se fusionnent sur leur fin réelle (l'identifiant décroissant
 * départageant deux fins égales) et les {@see self::TERMINATED_LIMIT} dernières
 * restent. L'ordre d'**affichage** est ensuite celui de l'octroi : la fin décide
 * *quelles* terminées on garde, pas l'ordre où on les montre.
 *
 * Un accord abandonné est un octroi dont le code n'a jamais été échangé : la
 * supplantation n'a lieu qu'à l'échange du code, et l'expiration d'une heure
 * n'est marquée en base que par la purge. Jusqu'à son passage, il n'est pas
 * révoqué ; il est lu à part et rangé parmi les terminées, état `Expired`.
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

    /** Combien d'expirées échangées une page de la lecture par clé en lit. */
    public const int EXCHANGED_PAGE_SIZE = 200;

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
            'terminated' => $this->readTerminated($threshold, $now->getTimezone()),
        ]);

        $connections = [
            ...$reads['alive'],
            ...array_map(static fn (EndedMcpConnection $ended): McpConnection => $ended->connection, $reads['terminated']),
        ];
        usort($connections, $this->newestGrantFirst(...));

        return array_map(fn (McpConnection $connection): AdministeredMcpConnectionView => $this->viewOf($connection, $now), $connections);
    }

    /**
     * Les {@see self::TERMINATED_LIMIT} dernières terminées à leur fin réelle, la
     * plus récente d'abord : les deux lectures triées par SQL, puis les pages des
     * expirées échangées tant qu'une ligne peut encore y entrer.
     *
     * @return list<EndedMcpConnection>
     */
    private function readTerminated(DateTimeImmutable $threshold, DateTimeZone $zone): array
    {
        $terminated = $this->latestEndFirst([
            ...$this->endedAt($this->connections->findTerminatedForAdministration(self::TERMINATED_LIMIT), $zone),
            ...$this->endedAt($this->connections->findAbandonedForAdministration($threshold, self::TERMINATED_LIMIT), $zone),
        ]);

        $after = null;
        do {
            $page = $this->connections->findExchangedExpiredForAdministration($after, self::EXCHANGED_PAGE_SIZE);
            if ([] === $page) {
                break;
            }
            $terminated = $this->latestEndFirst([...$terminated, ...$this->endedAt($page, $zone)]);
            $after = $page[\count($page) - 1];
        } while (self::EXCHANGED_PAGE_SIZE === \count($page) && !$this->nothingCanEnter($after, $terminated));

        return $terminated;
    }

    /**
     * Le `revoked_at` borne la fin réelle d'une expirée : si celui de la dernière
     * ligne lue est strictement sous la meilleure fin que la liste pleine ne peut
     * plus battre, toutes les suivantes (`revoked_at` décroissant) finissent plus tôt.
     * Strict : à égalité, l'identifiant peut encore départager en leur faveur.
     *
     * @param list<EndedMcpConnection> $terminated
     */
    private function nothingCanEnter(McpConnection $lastRead, array $terminated): bool
    {
        if (\count($terminated) < self::TERMINATED_LIMIT) {
            return false;
        }
        $revokedAt = $lastRead->revokedAt();

        return null !== $revokedAt && $revokedAt < $terminated[self::TERMINATED_LIMIT - 1]->endedAt;
    }

    /**
     * @param list<McpConnection> $connections
     *
     * @return list<EndedMcpConnection>
     */
    private function endedAt(array $connections, DateTimeZone $zone): array
    {
        return array_map(fn (McpConnection $connection): EndedMcpConnection => new EndedMcpConnection($connection, $this->endOf($connection, $zone)), $connections);
    }

    /**
     * L'instant où la connexion a **réellement** pris fin : la révocation si une
     * personne, le client, un rejeu ou une supplantation l'a posée ; sinon (marquée
     * expirée par la purge, ou abandonnée que la purge n'a pas vue) l'échéance que
     * le domaine calcule — le délai du code pour un accord jamais échangé, la plus
     * proche des échéances d'inactivité et de plafond sinon.
     */
    private function endOf(McpConnection $connection, DateTimeZone $zone): DateTimeImmutable
    {
        $revokedAt = $connection->revokedAt();
        if (null !== $revokedAt && RevocationCause::Expired !== $connection->revocationCause()) {
            return $revokedAt;
        }

        return null === $connection->codeExchangedAt()
            ? $this->lifetimes->unexchangedDeadline($connection->grantedAt()->setTimezone($zone))
            : $connection->deadline($zone, $this->lifetimes);
    }

    /**
     * Fin décroissante, l'identifiant décroissant départageant deux fins égales
     * (instants comparés, jamais leur texte) ; seules les {@see self::TERMINATED_LIMIT}
     * premières restent.
     *
     * @param list<EndedMcpConnection> $ended
     *
     * @return list<EndedMcpConnection>
     */
    private function latestEndFirst(array $ended): array
    {
        usort($ended, static function (EndedMcpConnection $a, EndedMcpConnection $b): int {
            $byEnd = $b->endedAt <=> $a->endedAt;

            return 0 !== $byEnd ? $byEnd : strcmp($b->connection->getId()->toRfc4122(), $a->connection->getId()->toRfc4122());
        });

        return \array_slice($ended, 0, self::TERMINATED_LIMIT);
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
