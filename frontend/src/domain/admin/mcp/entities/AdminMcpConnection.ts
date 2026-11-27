import type { AdminMcpClientRegistration } from './AdminMcpClient'

/**
 * Les états d'une connexion, tels que le serveur les écrit :
 * - `active`  : en vigueur ;
 * - `expired` : échue, que la purge l'ait marquée ou non — le serveur juge
 *               l'échéance, le frontend ne recalcule rien ;
 * - `revoked` : coupée, `revokedAt` et `revocationCause` disent quand et pourquoi.
 *
 * Volontairement distincte de `McpConnectionState` (`domain/mcp`) : les deux
 * vues sont deux contrats du serveur, qui divergent déjà (`expiresAt`, voir
 * plus bas). La liste est la source des gardes du dépôt HTTP, jamais un `as`.
 */
export const ADMIN_MCP_CONNECTION_STATES = ['active', 'expired', 'revoked'] as const

export type AdminMcpConnectionState = (typeof ADMIN_MCP_CONNECTION_STATES)[number]

/**
 * Une connexion MCP vue par l'administration (`GET /api/backoffice/mcp/connections`,
 * #652, spec 0007 T13), en **lecture seule** : la révocation par
 * l'administrateur n'existe pas (la personne révoque les siennes). Reflet
 * exact de `BackofficeMcpConnectionResource`.
 *
 * Ne porte jamais de jeton, d'empreinte, de secret ni de code d'autorisation :
 * le serveur n'en écrit pas dans cette liste.
 *
 * La liste rend toutes les connexions vivantes et les 200 terminées les plus
 * récentes, sans dire si elle tronque (décision D-D) : la page annonce la
 * borne, elle ne la déduit pas.
 */
export interface AdminMcpConnection {
  /** UUID de la connexion ; aucune route d'élément ne lui correspond. */
  readonly id: string
  /** Nom du compte propriétaire de la connexion. */
  readonly account: string
  readonly clientId: string
  readonly clientName: string
  readonly clientRegistration: AdminMcpClientRegistration
  /** Instants au format ATOM, tels que le serveur les écrit. */
  readonly grantedAt: string
  /**
   * Le code de l'accord a-t-il été échangé ? `false` : un accord abandonné,
   * qui n'a jamais abouti (aucun jeton émis) ; `true` : le client a reçu ses
   * jetons, même si `lastUsedAt` reste nul — il n'est écrit qu'au rafraîchissement.
   */
  readonly codeExchanged: boolean
  readonly lastUsedAt: string | null
  /**
   * L'échéance de la connexion, **toujours rendue**, révoquée comprise : pour
   * une connexion révoquée, c'est l'échéance qu'elle aurait eue, pas l'instant
   * où elle a cessé (`revokedAt`). Diffère de « Mes connexions MCP », où elle
   * vaut `null` exactement pour une connexion révoquée.
   */
  readonly expiresAt: string
  readonly state: AdminMcpConnectionState
  readonly revokedAt: string | null
  /**
   * Pourquoi la connexion est coupée (`person`, `client`, `refresh-token-reuse`,
   * `code-replay`, `superseded`, `expired`, `password-changed`,
   * `trusted-tier-withdrawn`…) ; nulle pour une connexion non révoquée,
   * accord abandonné compris. Volontairement une chaîne et non une union
   * fermée : une cause qu'un backend plus récent ajouterait doit rester
   * affichable (libellé générique) plutôt que de faire échouer toute la liste.
   */
  readonly revocationCause: string | null
}
