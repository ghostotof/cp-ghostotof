<?php

declare(strict_types=1);

namespace App\Shared\Application\Security;

use App\Shared\Domain\Security\McpConnectionReference;

/**
 * Ce que {@see AccountMcpConnectionsRevokerInterface::revokeAllOf()} a
 * effectivement révoqué pour un compte (#650) : la raison, et une référence
 * par connexion que l'appel a révoquée — de quoi les auditer **après** le
 * COMMIT de l'appelant, sans seconde requête. Une connexion déjà révoquée n'y
 * figure pas (elle garde sa première révocation et n'est pas auditée deux fois).
 *
 * Désigne les connexions par leur compte (UUID, #363), leur UUID et
 * l'identifiant **public** de leur client ; aucun jeton, code ni empreinte.
 */
final readonly class RevokedAccountMcpConnections
{
    /**
     * @param list<McpConnectionReference> $connections les connexions révoquées par cet appel, vide si le compte n'en tenait aucune d'active
     */
    public function __construct(
        private AccountAccessWithdrawal $reason,
        private array $connections,
    ) {
    }

    public function reason(): AccountAccessWithdrawal
    {
        return $this->reason;
    }

    /**
     * @return list<McpConnectionReference>
     */
    public function connections(): array
    {
        return $this->connections;
    }
}
