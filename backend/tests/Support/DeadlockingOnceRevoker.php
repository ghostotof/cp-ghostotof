<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Application\Security\AccountAccessWithdrawal;
use App\Shared\Application\Security\AccountMcpConnectionsRevokerInterface;
use App\Shared\Application\Security\RevokedAccountMcpConnections;
use App\Shared\Domain\Security\AccountReference;

/**
 * Le port des connexions MCP, dont les `$failures` premiers appels lèvent un
 * interblocage (`40P01`) après que l'appelant a écrit : les suivants délèguent
 * au vrai.
 */
final class DeadlockingOnceRevoker implements AccountMcpConnectionsRevokerInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly AccountMcpConnectionsRevokerInterface $real,
        private int $failures,
    ) {
    }

    public function revokeAllOf(AccountReference $account, AccountAccessWithdrawal $reason): RevokedAccountMcpConnections
    {
        ++$this->calls;
        if ($this->failures > 0) {
            --$this->failures;

            throw DeadlockedStatement::exception();
        }

        return $this->real->revokeAllOf($account, $reason);
    }

    public function auditRevoked(RevokedAccountMcpConnections $revoked): void
    {
        $this->real->auditRevoked($revoked);
    }
}
