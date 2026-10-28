/**
 * Une connexion MCP de la personne, telle que « Mes connexions MCP » la
 * présente (#650, spec 0007 tâche 11). Aucun jeton ni empreinte : le serveur
 * n'en écrit jamais dans cette liste.
 *
 * - `active`  : en vigueur ;
 * - `expired` : échue, que la purge l'ait marquée ou non — le serveur juge
 *               l'échéance, le frontend ne recalcule rien ;
 * - `revoked` : coupée, `revokedAt` et `revocationCause` disent quand et pourquoi.
 */
export type McpConnectionState = 'active' | 'expired' | 'revoked'

/** Les états que le serveur écrit ; un autre est une réponse que le frontend ne comprend pas. */
export const MCP_CONNECTION_STATES: readonly McpConnectionState[] = ['active', 'expired', 'revoked']

export interface McpConnectionSummary {
  /** UUID de la connexion, l'identifiant de `DELETE /api/me/mcp-connections/{id}`. */
  id: string
  client: { id: string; name: string }
  /** Instants au format ATOM, tels que le serveur les écrit. */
  grantedAt: string
  lastUsedAt: string | null
  expiresAt: string
  state: McpConnectionState
  revokedAt: string | null
  /**
   * Pourquoi la connexion est coupée (`person`, `client`, `refresh-token-reuse`,
   * `code-replay`, `superseded`, `expired`, `password-changed`,
   * `trusted-tier-withdrawn`…). Volontairement une chaîne et non une union
   * fermée : une cause qu'un backend plus récent ajouterait doit rester
   * affichable (libellé générique) plutôt que de faire échouer toute la liste.
   */
  revocationCause: string | null
}
