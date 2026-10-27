<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\ValueObject;

use App\Shared\Domain\Security\McpConnectionReference;
use Symfony\Component\Uid\Uuid;

/**
 * Ce que la révocation d'une connexion MCP a effectivement révoqué (#649), lu
 * dans la ligne que la base vient d'écrire (`RETURNING`), jamais dans une
 * instance chargée plus tôt : de quoi l'auditer sans autre requête.
 *
 * Désigne le compte par son UUID seul (#363), le client par son identifiant
 * **public** OAuth (`mcp_client.client_id`, un client public n'a pas de
 * secret) ; aucun jeton, code ni empreinte. Un McpConnectionReference : le
 * journal de sécurité le reçoit tel quel, sans que l'appelant le déplie. La
 * connexion est une ConnectionReference (#725), la cause s'y ajoute : le trio
 * est écrit une fois.
 */
final readonly class RevokedConnection implements McpConnectionReference
{
    /**
     * @param ConnectionReference $connection la connexion révoquée, telle que la ligne écrite la désigne
     */
    public function __construct(
        private ConnectionReference $connection,
        private RevocationCause $cause,
    ) {
    }

    public function connectionId(): Uuid
    {
        return $this->connection->connectionId();
    }

    public function accountId(): string
    {
        return $this->connection->accountId();
    }

    public function clientId(): string
    {
        return $this->connection->clientId();
    }

    public function cause(): RevocationCause
    {
        return $this->cause;
    }
}
