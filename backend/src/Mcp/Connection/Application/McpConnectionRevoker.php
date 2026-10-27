<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\Exception\CompromisedMcpConnectionException;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\RevokedConnection;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use Psr\Clock\ClockInterface;

/**
 * La révocation d'une connexion MCP compromise, puis son audit (#649).
 *
 * La révocation est celle du dépôt, une instruction SQL hors de l'unité de
 * travail ({@see McpConnectionRepositoryInterface::revokeActive()}) : elle tient
 * après une transaction annulée qui a fermé l'EntityManager, et c'est la base
 * qui dit si elle a eu lieu. L'audit suit, **après** l'écriture, et seulement
 * pour la révocation effective — la ligne porte ce que la base a rendu, jamais
 * ce que l'appelant croyait savoir de la connexion.
 *
 * **Typé par la compromission** (#726, CR6) : la connexion et la cause sont
 * celles de CompromisedMcpConnectionException, scellée sur ses deux
 * sous-classes — la réutilisation d'un rafraîchissement
 * (`refresh-token-reuse`) et le rejeu d'un code (`code-replay`). Les causes
 * qui naissent ailleurs ne s'y écrivent pas : l'expiration, que la purge
 * révoque dans sa transaction et audite avec l'acteur `system`
 * ({@see SecurityAuditLoggerInterface::mcpConnectionExpired()}) après le
 * COMMIT ; le remplacement, que le premier échange du code d'un nouvel accord
 * révoque dans sa transaction et audite en nommant la connexion qui remplace
 * ({@see SecurityAuditLoggerInterface::mcpConnectionSuperseded()}) ; la
 * révocation par le client, dans la transaction du point de révocation. Une
 * cause réservée n'est plus refusée à l'exécution : elle ne se demande plus,
 * et PHPStan le juge. L'audit porte l'acteur lu dans le jeton de sécurité de
 * la requête.
 */
final readonly class McpConnectionRevoker implements McpConnectionRevokerInterface
{
    public function __construct(
        private McpConnectionRepositoryInterface $connections,
        private SecurityAuditLoggerInterface $auditLogger,
        private ClockInterface $clock,
    ) {
    }

    public function revoke(CompromisedMcpConnectionException $compromise): ?RevokedConnection
    {
        $revoked = $this->connections->revokeActive($compromise->connectionId(), $compromise->revocationCause(), $this->clock->now());
        if (null === $revoked) {
            return null;
        }

        $this->auditLogger->mcpConnectionRevoked($revoked, $revoked->cause()->value);

        return $revoked;
    }
}
