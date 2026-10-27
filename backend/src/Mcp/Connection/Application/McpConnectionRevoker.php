<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\RevocationCause;
use App\Mcp\Connection\Domain\ValueObject\RevokedConnection;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * La révocation d'une connexion MCP, puis son audit (#649).
 *
 * La révocation est celle du dépôt, une instruction SQL hors de l'unité de
 * travail ({@see McpConnectionRepositoryInterface::revokeActive()}) : elle tient
 * après une transaction annulée qui a fermé l'EntityManager, et c'est la base
 * qui dit si elle a eu lieu. L'audit suit, **après** l'écriture, et seulement
 * pour la révocation effective — la ligne porte ce que la base a rendu, jamais
 * ce que l'appelant croyait savoir de la connexion.
 *
 * L'expiration n'est pas une de ses causes : elle naît de la purge
 * planifiée, qui révoque dans sa transaction et audite avec l'acteur `system`
 * ({@see SecurityAuditLoggerInterface::mcpConnectionExpired()}) après le
 * COMMIT. Demandée ici, elle est refusée avant toute écriture
 * ({@see RevocationCauseReservedToThePurgeException}). Le remplacement non
 * plus (#726, B3) : le premier échange du code d'un nouvel accord révoque dans
 * sa transaction et audite après le COMMIT en nommant la connexion qui
 * remplace ({@see SecurityAuditLoggerInterface::mcpConnectionSuperseded()}) ;
 * demandé ici, il est refusé avant toute écriture
 * ({@see RevocationCauseReservedToTheExchangeException}). Toute autre cause
 * est auditée avec l'acteur lu dans le jeton de sécurité de la requête.
 */
final readonly class McpConnectionRevoker implements McpConnectionRevokerInterface
{
    public function __construct(
        private McpConnectionRepositoryInterface $connections,
        private SecurityAuditLoggerInterface $auditLogger,
        private ClockInterface $clock,
    ) {
    }

    public function revoke(Uuid $connectionId, RevocationCause $cause): ?RevokedConnection
    {
        if (RevocationCause::Expired === $cause) {
            throw RevocationCauseReservedToThePurgeException::expired();
        }

        if (RevocationCause::Superseded === $cause) {
            throw RevocationCauseReservedToTheExchangeException::superseded();
        }

        $revoked = $this->connections->revokeActive($connectionId, $cause, $this->clock->now());
        if (null === $revoked) {
            return null;
        }

        $this->auditLogger->mcpConnectionRevoked($revoked, $revoked->cause()->value);

        return $revoked;
    }
}
