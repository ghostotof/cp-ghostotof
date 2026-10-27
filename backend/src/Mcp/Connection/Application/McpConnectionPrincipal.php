<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Shared\Domain\Security\McpConnectionReference;

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
 * Elle étend McpConnectionReference, donc AccountReference : tout ce qui
 * désigne déjà un compte par son UUID (PrincipalDesignation, donc le canal
 * `security` et `security_audit`) désigne aussi le compte d'une connexion,
 * sans rien apprendre de plus ; la connexion (`connectionId()`) et le client
 * (`clientId()`, son identifiant public OAuth) sont ceux que le journal de
 * sécurité lit sur toute connexion.
 */
interface McpConnectionPrincipal extends McpConnectionReference
{
    /**
     * Le rôle du palier de confiance (ADR 0003) qu'une connexion exige de son
     * compte, tel que `security.yaml` l'écrit : nommé ici plutôt que lu sur
     * CpgUser, pour que ce contexte ne dépende de `Security` que par ses deux
     * portes admises (`.claude/rules/mcp-oauth.md`). L'authentificateur le
     * juge à chaque requête, l'outil nominatif le revérifie ;
     * McpConnectionPrincipalTest le compare à `CpgUser::ROLE_TRUSTED`.
     */
    public const string TRUSTED_TIER = 'ROLE_TRUSTED';

    /**
     * Vrai quand le scope stocké de la connexion, relu avec le jeton, accorde
     * `nominative:read` (McpConnection::grantsNominativeRead()).
     */
    public function grantsNominativeRead(): bool;
}
