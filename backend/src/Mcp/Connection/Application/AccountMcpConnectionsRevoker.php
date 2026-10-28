<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\RevocationCause;
use App\Shared\Application\Security\AccountAccessWithdrawal;
use App\Shared\Application\Security\AccountMcpConnectionsRevokerInterface;
use App\Shared\Application\Security\RevokedAccountMcpConnections;
use App\Shared\Domain\Security\AccountReference;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * L'implémentation, dans `Mcp/Connection`, du port de `Shared` que
 * `Security/User` appelle dans sa transaction (#650, décision 3 du plan) : le
 * mot de passe changé, ou le palier de confiance retiré, coupe toutes les
 * connexions MCP du compte.
 *
 * **Jamais {@see McpConnectionRevokerInterface::revoke()}**, typé par la
 * compromission (#726, CR6) : la rouvrir à une cause générique ramènerait les
 * gardes à l'exécution que CR6 a retirées. La cause se déduit de la raison du
 * port par un `match` exhaustif (une raison sans cause est refusée par
 * PHPStan), et la révocation passe par la méthode dédiée du dépôt
 * ({@see McpConnectionRepositoryInterface::revokeAllActiveOfAccount()}, qui
 * exige la transaction de l'appelant).
 *
 * **Deux temps** : `revokeAllOf()` écrit dans la transaction de l'appelant et
 * n'audite rien ; `auditRevoked()` suit son COMMIT. Chaque ligne est tentée à
 * part par {@see RevokedConnectionAudit} : une panne de l'audit ne change pas l'issue
 * (le mot de passe est déjà changé, les connexions déjà coupées) et ne tait
 * pas les lignes suivantes (#725).
 */
final readonly class AccountMcpConnectionsRevoker implements AccountMcpConnectionsRevokerInterface
{
    public function __construct(
        private McpConnectionRepositoryInterface $connections,
        private ClockInterface $clock,
        private RevokedConnectionAudit $revokedAudit,
    ) {
    }

    public function revokeAllOf(AccountReference $account, AccountAccessWithdrawal $reason): RevokedAccountMcpConnections
    {
        return new RevokedAccountMcpConnections(
            $reason,
            $this->connections->revokeAllActiveOfAccount(
                Uuid::fromString($account->accountId()),
                $this->causeOf($reason),
                $this->clock->now(),
            ),
        );
    }

    public function auditRevoked(RevokedAccountMcpConnections $revoked): void
    {
        $cause = $this->causeOf($revoked->reason())->value;

        foreach ($revoked->connections() as $connection) {
            // L'acteur est lu comme à l'ordinaire : l'administrateur qui a
            // changé le mot de passe ou retiré le rôle.
            $this->revokedAudit->record($connection, $cause);
        }
    }

    /**
     * La seule correspondance entre le port de `Shared` et les causes de
     * `Mcp/Connection`, exhaustive.
     */
    private function causeOf(AccountAccessWithdrawal $reason): RevocationCause
    {
        return match ($reason) {
            AccountAccessWithdrawal::PasswordChanged => RevocationCause::PasswordChanged,
            AccountAccessWithdrawal::TrustedTierWithdrawn => RevocationCause::TrustedTierWithdrawn,
        };
    }
}
