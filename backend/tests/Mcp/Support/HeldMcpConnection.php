<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Support;

/**
 * Une connexion MCP accordée par le vrai parcours OAuth
 * ({@see HoldsAnMcpConnection::holdMcpConnection()}) : le jeton d'accès que le
 * client a reçu, et ce qui désigne ses lignes en base — le compte, la
 * connexion, le client — pour qu'un test les altère ou les compare à ce que
 * le firewall a authentifié.
 *
 * Le jeton n'est jamais un littéral : il vient du point de jeton (league, 80
 * hexadécimaux aléatoires).
 */
final readonly class HeldMcpConnection
{
    public function __construct(
        /** Le jeton d'accès en clair, tel que le client le présente en `Authorization: Bearer`. */
        public string $accessToken,
        /** UUID RFC 4122 du compte (`cpg_user.id`). */
        public string $accountId,
        /** UUID RFC 4122 de la connexion (`mcp_connection.id`). */
        public string $connectionId,
        /** L'identifiant public OAuth du client (`mcp_client.client_id`). */
        public string $clientId,
    ) {
    }
}
