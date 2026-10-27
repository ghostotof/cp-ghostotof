<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\ValueObject;

use App\Shared\Domain\Security\McpConnectionReference;
use Symfony\Component\Uid\Uuid;

/**
 * Ce par quoi une connexion MCP se désigne, lu dans sa ligne sans charger
 * l'entité (#725) : l'audit d'un refus au point de jeton, écrit après une
 * transaction que `wrapInTransaction()` a annulée et dont l'EntityManager est
 * fermé.
 *
 * Désigne le compte par son UUID seul (#363), le client par son identifiant
 * **public** OAuth ; aucun jeton, code ni empreinte.
 */
final readonly class ConnectionReference implements McpConnectionReference
{
    /**
     * @param string $accountId UUID du compte, forme RFC 4122 (minuscules)
     * @param string $clientId  identifiant public OAuth du client de la connexion
     */
    public function __construct(
        private Uuid $connectionId,
        private string $accountId,
        private string $clientId,
    ) {
    }

    public function connectionId(): Uuid
    {
        return $this->connectionId;
    }

    public function accountId(): string
    {
        return $this->accountId;
    }

    public function clientId(): string
    {
        return $this->clientId;
    }
}
