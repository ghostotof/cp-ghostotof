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
  /**
   * Le code de l'accord a-t-il été échangé ? `false` : un accord abandonné, qui
   * n'a jamais abouti (aucun jeton émis) ; `true` : le client a reçu ses jetons,
   * même si `lastUsedAt` reste nul — il n'est écrit qu'au rafraîchissement, une
   * connexion échangée et utilisée dans sa première heure n'a donc jamais « jamais
   * servi » (#650, R5a).
   */
  codeExchanged: boolean
  lastUsedAt: string | null
  /**
   * L'échéance jugée par le serveur ; `null` exactement pour une connexion
   * **révoquée**, qui n'en a plus : le serveur le dit, la page n'a pas à décider
   * de taire une date (#650, R5a).
   */
  expiresAt: string | null
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

/**
 * Ce que la liste « Mes connexions MCP » rend : les connexions — toutes celles
 * qui ne sont pas révoquées, plus les dernières terminées —, et si l'historique
 * des terminées est coupé (`terminatedTruncated` : il en existe de plus
 * anciennes que la page ne montre pas). Le serveur fixe la borne, le frontend
 * n'en connaît pas le nombre.
 */
export interface McpConnectionList {
  connections: McpConnectionSummary[]
  terminatedTruncated: boolean
}
