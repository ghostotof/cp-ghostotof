<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\Exception\McpPurgeRetentionTooShortException;
use App\Mcp\Connection\Domain\Repository\McpAccessTokenRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpAuthorizationCodeRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpRefreshTokenRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\ConnectionLifetimes;
use App\Mcp\Connection\Domain\ValueObject\RevokedConnection;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Shared\Domain\ValueObject\RetentionPeriod;
use DateInterval;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * La purge quotidienne des connexions MCP et de leurs jetons (#649).
 *
 * **Une transaction, dans l'ordre du point de jeton** — toutes les lignes
 * de connexion d'abord, les jetons ensuite (l'ordre inverse a été mesuré en
 * interblocage `40P01`, tâche 6) : présélection des connexions peut-être
 * expirées, sous verrou ; jugement de chacune par
 * {@see McpConnection::isExpiredAt()}, la seule définition de l'échéance ;
 * révocation de celles qu'il dit expirées (cause `expired`) ; suppression des
 * connexions révoquées avant la rétention — une suppression verrouille ses
 * lignes, et la placer après celle des jetons prendrait des connexions après
 * des jetons, l'ordre qu'une suppression de compte en cascade (`cpg_user` →
 * `mcp_connection` → jetons) croiserait ; puis suppression des codes et
 * jetons morts. **L'audit suit le COMMIT** : une
 * ligne `mcp-connection-revoked` (acteur `system`) par connexion marquée, aucune
 * si la transaction est annulée.
 *
 * Tout instant vient de l'horloge (`ClockInterface::now()`, Paris) : c'est
 * celui que juge l'authentificateur, et `isExpiredAt()` compte les jours sur
 * le calendrier de son fuseau.
 */
final readonly class McpConnectionPurger implements McpConnectionPurgerInterface
{
    /** Plancher de la rétention, comme la purge des invitations. */
    private const string MINIMUM_RETENTION = 'P1D';

    /**
     * Marge de la présélection SQL : ses bornes (maintenant moins la durée)
     * sont reculées d'un jour, pour qu'aucune connexion qu'`isExpiredAt()` dit
     * expirée n'y échappe — `$now - P30D` et l'échéance `dernier usage + P30D`
     * ne se répondent qu'à l'heure près autour d'un changement d'heure. La
     * présélection est large, le jugement exact.
     */
    private const string PRESELECTION_MARGIN = 'P1D';

    public function __construct(
        private McpConnectionRepositoryInterface $connections,
        private McpAuthorizationCodeRepositoryInterface $authorizationCodes,
        private McpAccessTokenRepositoryInterface $accessTokens,
        private McpRefreshTokenRepositoryInterface $refreshTokens,
        private TransactionInterface $transaction,
        private ConnectionLifetimes $lifetimes,
        private SecurityAuditLoggerInterface $auditLogger,
        private ClockInterface $clock,
    ) {
    }

    public function purge(RetentionPeriod $retention): McpConnectionPurgeResult
    {
        $now = $this->clock->now();
        $threshold = $now->sub($retention->interval());

        // Avant toute écriture : une rétention de quelques heures supprimerait
        // les connexions révoquées dans la journée, leur seule trace en base.
        if ($threshold > $now->sub(new DateInterval(self::MINIMUM_RETENTION))) {
            throw McpPurgeRetentionTooShortException::belowOneDay();
        }

        $purged = $this->transaction->run(fn (): array => [
            // La révocation d'abord : elle verrouille les connexions avant
            // qu'une suppression touche à leurs jetons, et les jetons des
            // connexions qu'elle marque partent dans la même transaction.
            'expired' => $this->revokeExpired($now),
            // Les connexions révoquées depuis la rétention, avant tout jeton :
            // leurs lignes sont verrouillées avant qu'une suppression de jeton
            // en verrouille une, comme partout ailleurs. Leurs jetons partent
            // par la cascade — il n'en reste pas, en principe : ceux d'une
            // connexion révoquée sont morts, supprimés la nuit suivante.
            'connections' => $this->connections->deleteRevokedBefore($threshold),
            'codes' => $this->authorizationCodes->deleteDead($now),
            // Les rafraîchissements avant les jetons d'accès : chaque jeton
            // d'accès supprimé remet à NULL le lien des rafraîchissements
            // restants (`ON DELETE SET NULL`), moins nombreux ainsi.
            'refreshTokens' => $this->refreshTokens->deleteDead($now),
            'accessTokens' => $this->accessTokens->deleteDead($now),
        ]);

        // Après le COMMIT : une transaction annulée n'a rien révoqué.
        foreach ($purged['expired'] as $revoked) {
            $this->auditLogger->mcpConnectionExpired($revoked, $revoked->connectionId, $revoked->clientId);
        }

        return new McpConnectionPurgeResult(
            array_map(static fn (RevokedConnection $revoked): string => $revoked->connectionId->toRfc4122(), $purged['expired']),
            $purged['codes'],
            $purged['accessTokens'],
            $purged['refreshTokens'],
            $purged['connections'],
            $threshold,
        );
    }

    /**
     * Présélectionne en SQL, juge en PHP, révoque ce que le jugement retient :
     * la purge et l'authentificateur ne peuvent pas diverger sur l'échéance,
     * fût-ce d'une seconde ou d'une heure de changement d'heure.
     *
     * @return list<RevokedConnection>
     */
    private function revokeExpired(DateTimeImmutable $now): array
    {
        $margin = new DateInterval(self::PRESELECTION_MARGIN);
        $candidates = $this->connections->findActiveCandidatesForExpiry(
            $now->sub($this->lifetimes->idle())->add($margin),
            $now->sub($this->lifetimes->maximum())->add($margin),
        );

        $expired = array_values(array_filter(
            $candidates,
            fn (McpConnection $connection): bool => $connection->isExpiredAt($now, $this->lifetimes),
        ));

        return $this->connections->revokeExpired(
            array_map(static fn (McpConnection $connection): Uuid => $connection->getId(), $expired),
            $now,
        );
    }
}
