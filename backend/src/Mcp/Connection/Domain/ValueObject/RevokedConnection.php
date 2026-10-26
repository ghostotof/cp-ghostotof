<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\ValueObject;

use App\Shared\Domain\Security\AccountReference;
use Symfony\Component\Uid\Uuid;

/**
 * Ce que la révocation d'une connexion MCP a effectivement révoqué (#649), lu
 * dans la ligne que la base vient d'écrire (`RETURNING`), jamais dans une
 * instance chargée plus tôt : de quoi l'auditer sans autre requête.
 *
 * Désigne le compte par son UUID seul (AccountReference, #363), le client par
 * son identifiant **public** OAuth (`mcp_client.client_id`, un client public
 * n'a pas de secret) ; aucun jeton, code ni empreinte.
 */
final readonly class RevokedConnection implements AccountReference
{
    /**
     * @param string $accountId UUID du compte, forme RFC 4122 (minuscules)
     * @param string $clientId  identifiant public OAuth du client de la connexion
     */
    public function __construct(
        public Uuid $connectionId,
        private string $accountId,
        public string $clientId,
        public RevocationCause $cause,
    ) {
    }

    public function accountId(): string
    {
        return $this->accountId;
    }
}
