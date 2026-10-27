<?php

declare(strict_types=1);

namespace App\Shared\Domain\Security;

use Symfony\Component\Uid\Uuid;

/**
 * Ce par quoi une connexion MCP se désigne hors de `Mcp` — dans le journal de
 * sécurité : le compte qui l'a accordée (son UUID, AccountReference, #363), la
 * connexion elle-même et le client à qui elle l'a été, par son identifiant
 * **public** OAuth (`mcp_client.client_id` : un client public n'a pas de
 * secret). Jamais un jeton, un code ni leur empreinte.
 *
 * Un seul type pour ce trio que chaque événement MCP du journal portait en
 * trois paramètres (`mcp-connection-granted`, `nominative-corpus-read`,
 * `mcp-connection-revoked`, `mcp-access-token-refused`, lot C des relectures
 * de #649) : l'appelant tenait déjà un objet qui les porte tous, et les
 * dépliait. Une interface dans `Shared`, comme AccountReference, plutôt
 * qu'une classe de `Mcp` : `Security` ne dépend pas de `Mcp`, et chaque
 * porteur l'implémente — la connexion (McpConnection), la révocation que la
 * base vient d'écrire (RevokedConnection), l'identité du firewall `mcp`
 * (McpConnectionPrincipal).
 */
interface McpConnectionReference extends AccountReference
{
    /** L'UUID de la connexion MCP. */
    public function connectionId(): Uuid;

    /** L'identifiant public OAuth du client de la connexion (`mcp_client.client_id`). */
    public function clientId(): string;
}
