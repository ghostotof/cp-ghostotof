<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Shared\Domain\Security\AccountReference;
use Symfony\Component\Uid\Uuid;

/**
 * Ce que le firewall `mcp` a authentifié (#648, ADR 0006 D4) : une connexion
 * MCP, relue en base à cette requête, et ce qui la désigne — le compte qui a
 * consenti (son UUID, jamais son identifiant de connexion, #363), la
 * connexion, le client à qui elle a été accordée (son identifiant public
 * OAuth).
 *
 * Une interface de la couche Application pour que l'outil nominatif et le
 * journal d'audit (#648, tâches suivantes) lisent l'identité par `Security`
 * sans dépendre de la classe d'utilisateur du firewall
 * ({@see \App\Mcp\Connection\Infrastructure\Security\McpConnectionUser}).
 * Elle étend AccountReference : tout ce qui désigne déjà un compte par son
 * UUID (PrincipalDesignation, donc le canal `security` et `security_audit`)
 * désigne aussi le compte d'une connexion, sans rien apprendre de plus.
 */
interface McpConnectionPrincipal extends AccountReference
{
    /** La connexion MCP dont le jeton présenté a été relu. */
    public function connectionId(): Uuid;

    /** L'identifiant public OAuth du client de la connexion (`mcp_client.client_id`). */
    public function clientId(): string;
}
