<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\Entity\McpConnection;
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
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * La purge quotidienne des connexions MCP et de leurs jetons (#649).
 *
 * **Deux transactions, le marquage puis les suppressions** (#726). La
 * première présélectionne les connexions peut-être expirées, sous verrou,
 * juge chacune par {@see McpConnection::isExpiredAt()}, la seule définition
 * de l'échéance, et révoque celles qu'il dit expirées (cause `expired`).
 * **L'audit suit son COMMIT** : une ligne `mcp-connection-revoked` (acteur
 * `system`) par connexion marquée, aucune si elle est annulée. Pourquoi la
 * coupure : le verrou des candidates ne dure que leur jugement — en une seule
 * transaction, une candidate encore vivante (présélection large) restait
 * verrouillée pendant toutes les suppressions, et son rafraîchissement
 * attendait la fin de la purge jusqu'au `lock_timeout` de FPM.
 *
 * La seconde supprime, **dans l'ordre du point de jeton** — toutes les lignes
 * de connexion d'abord, les jetons ensuite (l'ordre inverse a été mesuré en
 * interblocage `40P01`, tâche 6 de #649) : les connexions révoquées avant la
 * rétention — une suppression verrouille ses lignes, et la placer après celle
 * des jetons prendrait des connexions après des jetons, l'ordre qu'une
 * suppression de compte en cascade (`cpg_user` → `mcp_connection` → jetons)
 * croiserait ; puis les codes, les jetons d'accès et les rafraîchissements
 * morts — A avant R, l'ordre de qui touche les deux (le point de révocation,
 * le grant de league, la cascade de la suppression d'un jeton d'accès). Les
 * jetons des connexions marquées sont morts depuis le premier COMMIT : ils
 * partent dans la même nuit.
 *
 * **Ce que la coupure coûte** : une seconde transaction en échec laisse les
 * connexions marquées sans leurs suppressions — leurs jetons morts, comme les
 * vieilles connexions révoquées, restent en base jusqu'à la nuit suivante, qui
 * les supprime ; leurs audits sont écrits, et la révocation a eu lieu (chaque
 * lecteur juge la connexion, ses jetons ne servent plus).
 *
 * **Une panne de l'audit ne change pas l'issue** (#726, B4 ; la règle de
 * #725, appliquée au point de jeton comme à la purge :
 * `.claude/rules/mcp-oauth-purge.md`, « The daily purge ») : entre les deux
 * transactions, chaque `mcpConnectionExpired()` est tenté pour lui-même ; une
 * panne sort en `critical` sur le canal principal, la classe seule — jamais
 * le message, qui pourrait citer une connexion —, et n'interrompt ni les
 * lignes suivantes ni les suppressions : la révocation a eu lieu, ses
 * jetons morts partent la même nuit.
 *
 * Tout instant vient de l'horloge (`ClockInterface::now()`, Paris) : c'est
 * celui que juge l'authentificateur, et `isExpiredAt()` compte les jours sur
 * le calendrier de son fuseau.
 */
final readonly class McpConnectionPurger implements McpConnectionPurgerInterface
{
    /**
     * Marge de la présélection SQL : ses bornes (maintenant moins la durée)
     * sont reculées d'un jour, pour qu'aucune connexion qu'`isExpiredAt()` dit
     * expirée n'y échappe — `$now - P30D` et l'échéance `dernier usage + P30D`
     * ne se répondent qu'à l'heure près autour d'un changement d'heure. La
     * présélection est large, le jugement exact. C'est aussi le plancher des
     * deux durées de connexion, que {@see ConnectionLifetimes} refuse en
     * dessous (relecture du lot B de #649) : plus courtes que la marge, la
     * borne serait dans le futur et chaque connexion active serait verrouillée
     * à chaque nuit — une constante pour les deux, lue là. Les durées doivent
     * même dépasser cette marge plus la durée d'un code plus l'heure d'un
     * changement d'heure et une minute de jeu (#726) : la connexion
     * d'un code encore échangeable, que l'échange verrouille la première,
     * n'est alors jamais candidate du marquage.
     */
    private const string PRESELECTION_MARGIN = ConnectionLifetimes::MINIMUM_CONNECTION_LIFETIME;

    public function __construct(
        private McpConnectionRepositoryInterface $connections,
        private McpAuthorizationCodeRepositoryInterface $authorizationCodes,
        private McpAccessTokenRepositoryInterface $accessTokens,
        private McpRefreshTokenRepositoryInterface $refreshTokens,
        private TransactionInterface $transaction,
        private ConnectionLifetimes $lifetimes,
        private SecurityAuditLoggerInterface $auditLogger,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function purge(RetentionPeriod $retention): McpConnectionPurgeResult
    {
        $now = $this->clock->now();
        // Avant toute écriture, le plancher d'un jour de RetentionPeriod : une
        // rétention de quelques heures supprimerait les connexions révoquées
        // dans la journée, leur seule trace en base.
        $threshold = $retention->thresholdAtLeastOneDayBefore($now);

        // Le marquage d'abord, dans sa propre transaction : le verrou des
        // candidates ne dure que leur jugement, relâché à ce COMMIT.
        $expired = $this->transaction->run(fn (): array => $this->revokeExpired($now));

        // Après le COMMIT du marquage : une transaction annulée n'a rien
        // révoqué ; validée, la révocation a eu lieu, que les suppressions
        // qui suivent aboutissent ou non — et que son audit aboutisse ou non.
        $this->auditExpired($expired);

        // Les suppressions dans une seconde transaction, sans le verrou des
        // candidates (McpConnectionPurgeLockOrderTest pince la coupure).
        $purged = $this->transaction->run(fn (): array => [
            // Les connexions révoquées depuis la rétention, avant tout jeton :
            // leurs lignes sont verrouillées avant qu'une suppression de jeton
            // en verrouille une, comme partout ailleurs. Leurs jetons partent
            // par la cascade — il n'en reste pas, en principe : ceux d'une
            // connexion révoquée sont morts, supprimés la nuit suivante.
            'connections' => $this->connections->deleteRevokedBefore($threshold),
            'codes' => $this->authorizationCodes->deleteDead($now),
            // Les jetons d'accès avant les rafraîchissements : A puis R, comme
            // le point de révocation, le grant de rafraîchissement de league et
            // la cascade `ON DELETE SET NULL` d'un jeton d'accès supprimé. Dans
            // l'ordre inverse, une révocation d'un rafraîchissement sur une
            // connexion déjà révoquée dont les jetons ne sont pas encore purgés
            // tenait A et attendait R quand cette purge tenait R (son `DELETE`)
            // et attendait A : un interblocage (40P01). Chaque jeton d'accès
            // supprimé remet à NULL le lien des rafraîchissements qui lui
            // survivent, dans un ordre comme dans l'autre — un rafraîchissement
            // vivant dont le jeton d'accès a expiré compris. Le surcoût de A
            // puis R sur R puis A, lui, ne touche que des lignes mortes : les
            // rafraîchissements morts que l'instruction suivante supprime,
            // réécrits pour rien. McpConnectionPurgeLockOrderTest pince les
            // deux ordres de cette transaction en processus réels.
            'accessTokens' => $this->accessTokens->deleteDead($now),
            'refreshTokens' => $this->refreshTokens->deleteDead($now),
        ]);

        return new McpConnectionPurgeResult(
            expired: array_map(static fn (RevokedConnection $revoked): string => $revoked->connectionId()->toRfc4122(), $expired),
            deletedAuthorizationCodes: $purged['codes'],
            deletedAccessTokens: $purged['accessTokens'],
            deletedRefreshTokens: $purged['refreshTokens'],
            deletedConnections: $purged['connections'],
            threshold: $threshold,
        );
    }

    /**
     * Une ligne `mcp-connection-revoked` (cause `expired`, acteur `system`)
     * par connexion marquée, chacune tentée pour elle-même : une panne de
     * l'écrivain ne tait pas les suivantes et ne saute pas les suppressions
     * (#726, B4). Elle sort en `critical` sur le canal principal, la classe
     * seule, jamais le message : une erreur du pilote pourrait citer la
     * connexion.
     *
     * @param list<RevokedConnection> $expired
     */
    private function auditExpired(array $expired): void
    {
        foreach ($expired as $revoked) {
            try {
                $this->auditLogger->mcpConnectionExpired($revoked);
            } catch (Throwable $failure) {
                $this->logger->critical('Expired connection not audited.', ['exceptionClass' => $failure::class]);
            }
        }
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
