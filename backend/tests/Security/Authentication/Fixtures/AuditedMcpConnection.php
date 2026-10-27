<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication\Fixtures;

use App\Shared\Domain\Security\AccountReference;
use App\Shared\Domain\Security\McpConnectionReference;
use Symfony\Component\Uid\Uuid;

/**
 * Une connexion MCP telle que le journal de sécurité la reçoit, sans ligne en
 * base ni classe de `Mcp` : le compte, l'UUID de la connexion et
 * l'identifiant public de son client, choisis par le test. Hors de `src/`,
 * jamais chargé par l'application.
 */
final readonly class AuditedMcpConnection implements McpConnectionReference
{
    public function __construct(
        private AccountReference $account,
        private Uuid $connectionId,
        private string $clientId,
    ) {
    }

    public function accountId(): string
    {
        return $this->account->accountId();
    }

    public function connectionId(): Uuid
    {
        return $this->connectionId;
    }

    public function clientId(): string
    {
        return $this->clientId;
    }
}
