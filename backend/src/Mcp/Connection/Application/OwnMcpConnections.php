<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\Exception\McpConnectionNotFoundException;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\ConnectionLifetimes;
use App\Mcp\Connection\Domain\ValueObject\RevocationCause;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Shared\Domain\Security\AccountReference;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * « Mes connexions MCP » (#650), la lecture : deux requêtes au plus (les
 * connexions non révoquées du compte, toutes ; les {@see self::TERMINATED_LIMIT}
 * dernières terminées), chacune avec son client joint, l'état et l'échéance
 * jugés à l'instant de l'horloge — Paris, comme partout dans la tranche.
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
        private SecurityAuditLoggerInterface $auditLogger,
        private FailSafeAudit $failSafeAudit,
    ) {
    }

    public function of(AccountReference $account): OwnMcpConnectionList
    {
        $now = $this->clock->now();
        $accountId = Uuid::fromString($account->accountId());

        // Une lecture par moitié : une ligne terminée de plus que la limite
        // suffit à dire qu'il y en a davantage, sans second COUNT.
        $terminated = $this->connections->findTerminatedOfAccount($accountId, self::TERMINATED_LIMIT + 1);
        $connections = [...$this->connections->findNotRevokedOfAccount($accountId), ...\array_slice($terminated, 0, self::TERMINATED_LIMIT)];
        // Les deux moitiés se mêlent dans l'ordre du client : octroi décroissant, identifiant décroissant.
        usort($connections, $this->newestGrantFirst(...));

        return new OwnMcpConnectionList(
            array_map(fn(McpConnection $connection): OwnMcpConnectionView => $this->viewOf($connection, $now), $connections),
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
        // change pas la réponse (#725, FailSafeAudit).
        $this->failSafeAudit->attempt(
            fn() => $this->auditLogger->mcpConnectionRevoked($revoked, $revoked->cause()->value),
            'Revoked connection not audited.',
        );
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
