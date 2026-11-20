<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\ConnectionLifetimes;
use App\Mcp\Connection\Domain\ValueObject\ExchangedExpiredCursor;
use App\Mcp\Connection\Domain\ValueObject\RevocationCause;
use DateInterval;
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
 *   ({@see ConnectionLifetimes::unexchangedDeadline()}), triés par octroi en SQL.
 *   Cette fin ne dépend que de l'octroi, **jamais du marquage** : c'est ce qui
 *   rend le tri SQL par octroi exact, sans arrêt anticipé. Elle passe par
 *   {@see McpConnection::deadline()}, la seule définition de l'échéance : la
 *   configuration impose `connection_unexchanged` sous `connection_idle`, donc pour
 *   une jamais échangée elle vaut `unexchangedDeadline()`, et chaque terme croît avec
 *   l'octroi ;
 * - les **expirées échangées** : la fin est la plus proche des échéances
 *   d'inactivité et de plafond ({@see McpConnection::deadline()}), bornée par
 *   leur marquage `revoked_at` (la fin est `min(échéance, revoked_at)`), qu'on ne
 *   calcule **que** en PHP — jour de calendrier d'un changement d'heure, fin
 *   de mois : PostgreSQL et PHP divergent. SQL les rend par
 *   activité décroissante (`COALESCE(last_used_at, granted_at)`), par pages à clé
 *   de reprise. La fin réelle ne dépasse jamais l'échéance d'inactivité de
 *   l'activité de la ligne (`min(échéance, revoked_at)`, l'échéance étant la plus
 *   proche de l'inactivité et du plafond) : une ligne non lue a une activité au plus
 *   égale à celle du curseur, donc une fin au plus égale au **majorant**
 *   `idleDeadline(activité du curseur)` plus un jour (marge des jours de calendrier,
 *   où PHP et PostgreSQL peuvent diverger). La lecture s'arrête dès que la
 *   {@see self::TERMINATED_LIMIT}-ième meilleure fin connue est **strictement**
 *   postérieure à ce majorant : aucune ligne suivante ne peut plus l'égaler. Le `min`
 *   avec `revoked_at` garde l'arrêt exact quand une durée `app.mcp.lifetime.*` est
 *   allongée après la purge (l'échéance recalculée dépasserait sinon le marquage) ;
 *   le majorant suit les durées en vigueur : allongées, il monte et la lecture va
 *   plus loin, jamais trop court.
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

        // La fin bornée déjà calculée des expirées échangées, par identifiant : la
        // lecture l'a établie pour les ranger, l'affichage la reprend sans la recalculer.
        $boundedEnds = [];
        foreach ($reads['terminated'] as $ended) {
            if (RevocationCause::Expired === $ended->connection->revocationCause() && null !== $ended->connection->codeExchangedAt()) {
                $boundedEnds[$ended->connection->getId()->toRfc4122()] = $ended->endedAt;
            }
        }

        $connections = [
            ...$reads['alive'],
            ...array_map(static fn (EndedMcpConnection $ended): McpConnection => $ended->connection, $reads['terminated']),
        ];
        usort($connections, $this->newestGrantFirst(...));

        return array_map(fn (McpConnection $connection): AdministeredMcpConnectionView => $this->viewOf($connection, $now, $boundedEnds[$connection->getId()->toRfc4122()] ?? null), $connections);
    }

    /**
     * Les {@see self::TERMINATED_LIMIT} dernières terminées à leur fin réelle, la
     * plus récente d'abord : les deux lectures triées par SQL, puis les pages des
     * expirées échangées, lues par activité décroissante.
     *
     * Arrêt anticipé exact : une ligne non lue a une activité au plus égale à celle
     * du curseur, donc une fin (au plus son échéance d'inactivité) au plus égale au
     * majorant `idleDeadline(activité du curseur)` + un jour. Quand on tient déjà
     * {@see self::TERMINATED_LIMIT} fins et que le majorant est **strictement**
     * antérieur à la dernière, aucune ligne suivante ne peut entrer ; à égalité, on
     * continue de lire. Instants comparés, jamais des chaînes.
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
            $after = ExchangedExpiredCursor::after($page[\count($page) - 1]);
            if (self::TERMINATED_LIMIT === \count($terminated) && $this->endUpperBound($after, $zone) < $terminated[self::TERMINATED_LIMIT - 1]->endedAt) {
                break;
            }
        } while (self::EXCHANGED_PAGE_SIZE === \count($page));

        return $terminated;
    }

    /**
     * Le majorant de la fin de toute expirée échangée non encore lue : l'échéance
     * d'inactivité de l'activité du curseur, plus un jour de calendrier (jamais
     * `modify()`). L'activité, hydratée avec un décalage fixe, est ramenée dans
     * `$zone` avant l'ajout, pour que les jours soient ceux de PHP.
     */
    private function endUpperBound(ExchangedExpiredCursor $cursor, DateTimeZone $zone): DateTimeImmutable
    {
        return $this->lifetimes->idleDeadline($cursor->activity->setTimezone($zone))->add(new DateInterval('P1D'));
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
     * le domaine calcule :
     * - un accord jamais échangé : {@see McpConnection::deadline()}, la seule définition
     *   de l'échéance (la plus proche de l'inactivité, du plafond et du délai du code
     *   non échangé), **sans** borne par le marquage : chacun de ces termes croît avec
     *   l'octroi, donc le tri SQL par octroi de sa lecture reste exact. Une borne par
     *   `revoked_at` le rendrait faux, le marquage ne suivant pas l'octroi ;
     * - un accord échangé : la même échéance, **bornée par `revoked_at`** — une durée
     *   allongée depuis la purge ne peut pas la repousser après le marquage, ce que
     *   l'arrêt anticipé de sa lecture suppose. C'est cette fin bornée que la liste
     *   affiche comme échéance de la ligne ({@see self::viewOf()}).
     */
    private function endOf(McpConnection $connection, DateTimeZone $zone): DateTimeImmutable
    {
        $revokedAt = $connection->revokedAt();
        if (null !== $revokedAt && RevocationCause::Expired !== $connection->revocationCause()) {
            return $revokedAt;
        }

        $deadline = $connection->deadline($zone, $this->lifetimes);
        if (null === $connection->codeExchangedAt()) {
            return $deadline;
        }

        return null !== $revokedAt && $revokedAt < $deadline ? $revokedAt : $deadline;
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

    /**
     * `$boundedEnd` : la fin bornée d'une expirée échangée (`min(échéance, revoked_at)`),
     * déjà calculée par {@see self::endOf()} pour la ranger ; elle est alors l'échéance
     * affichée, car l'échéance recalculée avec des durées allongées depuis la purge
     * dépasserait le marquage. `null` pour toute autre ligne (vivante, abandonnée non
     * marquée, révoquée par une autre cause) : elle garde `expiresAt()`.
     */
    private function viewOf(McpConnection $connection, DateTimeImmutable $now, ?DateTimeImmutable $boundedEnd): AdministeredMcpConnectionView
    {
        $zone = $now->getTimezone();
        // Une seule évaluation de l'échéance par ligne : celle qui est rendue
        // est celle qui juge l'état.
        $deadline = $boundedEnd?->setTimezone($zone) ?? $this->status->expiresAt($connection);
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
