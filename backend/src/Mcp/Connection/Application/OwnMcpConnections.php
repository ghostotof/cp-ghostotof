<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\Exception\McpConnectionNotFoundException;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\ConnectionLifetimes;
use App\Mcp\Connection\Domain\ValueObject\RevocationCause;
use App\Shared\Domain\Security\AccountReference;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * « Mes connexions MCP » (#650), la lecture : deux requêtes au plus (les
 * connexions non révoquées du compte, toutes ; les {@see self::TERMINATED_LIMIT}
 * dernières *terminées* — au sens de leur instant de fin, pas de leur octroi),
 * chacune avec son client joint, **lues dans un même instantané**
 * ({@see ReadSnapshotInterface}), l'état et l'échéance jugés à l'instant de
 * l'horloge — Paris, comme partout dans la tranche. L'ordre d'**affichage** reste
 * celui de l'octroi : l'instant de fin décide *quelles* terminées on garde, pas
 * l'ordre où on les montre.
 *
 * **La révocation par la personne** est une instruction, sans
 * `TransactionInterface` ({@see McpConnectionRepositoryInterface::revokeActiveOfAccount()}) :
 * l'instruction *est* la transaction (autocommit), l'audit suit son retour,
 * donc son COMMIT — une transaction autour n'ajouterait rien. Jamais
 * {@see McpConnectionRevokerInterface::revoke()}, typé par la compromission
 * (#726, CR6) : la rouvrir à une cause générique ramènerait les gardes à
 * l'exécution que CR6 a retirées. **Ordre des verrous** : la connexion seule,
 * `FOR NO KEY UPDATE`, par identifiant ; cette instruction ne tient rien
 * d'autre en attendant une ligne, donc aucun cycle ne peut la contenir (les
 * cycles possibles de la tranche, tous entre transactions qui tiennent une
 * connexion en en attendant une autre, sont ceux de
 * {@see \App\Mcp\Connection\Application\TokenEndpoint}). Une connexion
 * **échue que la purge n'a pas encore marquée** n'est pas révoquée — la
 * personne la révoque `person`, pas `expired` : la cause dit qui a coupé.
 *
 * **L'échéance affichée est celle que l'on juge** : {@see McpConnection::deadline()},
 * dont {@see McpConnection::isExpiredAt()} est la comparaison. Deux calculs
 * auraient pu se séparer ; il n'y en a qu'un.
 */
final readonly class OwnMcpConnections implements OwnMcpConnectionsInterface
{
    /**
     * Combien de connexions terminées la liste montre, au plus : **le seul
     * endroit** où ce nombre est écrit (la page n'en cite aucun, elle lit
     * `terminatedTruncated`).
     */
    public const int TERMINATED_LIMIT = 50;

    public function __construct(
        private McpConnectionRepositoryInterface $connections,
        private ClockInterface $clock,
        private ConnectionLifetimes $lifetimes,
        private RevokedConnectionAudit $revokedAudit,
        private ReadSnapshotInterface $snapshot,
    ) {
    }

    public function of(AccountReference $account): OwnMcpConnectionList
    {
        $now = $this->clock->now();
        $accountId = Uuid::fromString($account->accountId());

        // Une lecture par moitié : une ligne terminée de plus que la limite
        // suffit à dire qu'il y en a davantage, sans second COUNT. **Les deux
        // dans le même instantané** (CR3) : une connexion révoquée entre les
        // deux requêtes ne doit ni disparaître de la liste ni y figurer deux fois.
        $halves = $this->snapshot->read(fn (): array => [
            'terminated' => $this->connections->findTerminatedOfAccount($accountId, self::TERMINATED_LIMIT + 1),
            'notRevoked' => $this->connections->findNotRevokedOfAccount($accountId),
        ]);
        $terminated = $halves['terminated'];
        $connections = [...$halves['notRevoked'], ...\array_slice($terminated, 0, self::TERMINATED_LIMIT)];
        // Les deux moitiés se mêlent dans l'ordre du client : octroi décroissant, identifiant décroissant.
        usort($connections, $this->newestGrantFirst(...));

        return new OwnMcpConnectionList(
            array_map(fn (McpConnection $connection): OwnMcpConnectionView => $this->viewOf($connection, $now), $connections),
            \count($terminated) > self::TERMINATED_LIMIT,
        );
    }

    public function revoke(AccountReference $account, Uuid $connectionId): void
    {
        $accountId = Uuid::fromString($account->accountId());
        $revoked = $this->connections->revokeActiveOfAccount($connectionId, $accountId, RevocationCause::Person, $this->clock->now());

        if (null === $revoked) {
            // Rien révoqué par cet appel : déjà révoquée (à elle : 204, rien
            // à écrire ni à auditer), ou pas à elle / inconnue (le même 404).
            if ($this->connections->belongsToAccount($connectionId, $accountId)) {
                return;
            }

            throw McpConnectionNotFoundException::forTheCaller();
        }

        // Après l'écriture, donc après son COMMIT ; une panne de l'audit ne
        // change pas la réponse (#725, RevokedConnectionAudit).
        $this->revokedAudit->record($revoked, $revoked->cause()->value);
    }

    /**
     * L'ordre des deux dépôts, rejoué sur les deux moitiés mêlées : octroi
     * décroissant, l'identifiant décroissant départageant deux octrois égaux.
     */
    private function newestGrantFirst(McpConnection $a, McpConnection $b): int
    {
        $byGrant = $b->grantedAt() <=> $a->grantedAt();

        return 0 !== $byGrant ? $byGrant : strcmp($b->getId()->toRfc4122(), $a->getId()->toRfc4122());
    }

    private function viewOf(McpConnection $connection, DateTimeImmutable $now): OwnMcpConnectionView
    {
        $zone = $now->getTimezone();
        // **Une fois par ligne** (#650, R5a) : l'échéance affichée et celle
        // qui juge l'état sont la même valeur, calculée ici et passée à
        // stateOf() — plus de seconde évaluation de deadline() par isExpiredAt().
        $deadline = $connection->deadline($zone, $this->lifetimes);
        $state = $this->stateOf($connection, $now, $deadline);

        return new OwnMcpConnectionView(
            $connection->getId(),
            $connection->client()->getClientId(),
            $connection->client()->getName(),
            $connection->grantedAt()->setTimezone($zone),
            null !== $connection->codeExchangedAt(),
            $connection->lastUsedAt()?->setTimezone($zone),
            // Une connexion révoquée n'a plus d'échéance : celle qu'on calculerait
            // serait une date qui n'arrivera à aucun effet, et l'API dit ce qui
            // est vrai plutôt que de laisser la page décider de la taire (#650, R5a).
            // Une `expired` la garde : c'est elle qui l'a fait expirer.
            OwnMcpConnectionState::Revoked === $state ? null : $deadline,
            $state,
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
     *   (`$now >= $deadline`, la comparaison de {@see McpConnection::isExpiredAt()},
     *   sur l'échéance déjà calculée pour la ligne) — jamais `revoked_at` seul,
     *   qui ne dit pas pourquoi (commentaire de #649) ;
     * - `active` sinon.
     *
     * Une connexion révoquée puis échue reste `revoked` : sa cause est ce qui
     * l'a coupée, et l'échéance n'y change rien.
     */
    private function stateOf(McpConnection $connection, DateTimeImmutable $now, DateTimeImmutable $deadline): OwnMcpConnectionState
    {
        $cause = $connection->revocationCause();

        if ($connection->isRevoked() && RevocationCause::Expired !== $cause) {
            return OwnMcpConnectionState::Revoked;
        }

        if (RevocationCause::Expired === $cause || $now >= $deadline) {
            return OwnMcpConnectionState::Expired;
        }

        return OwnMcpConnectionState::Active;
    }
}
