/**
 * Les deux modes d'enregistrement d'un client MCP, tels que le serveur les
 * écrit (`ClientRegistration` côté backend) :
 * - `preregistered` : enregistré à l'avance par l'administrateur du site, son
 *   nom et ses redirections se révisent depuis le backoffice ;
 * - `cimd` : déclaré par son document de métadonnées (#651), qui fait foi — il
 *   ne se révise pas à la main (409 `client-declared-by-document`), il se
 *   supprime seulement.
 *
 * La liste est la source des gardes du dépôt HTTP : un mode que le frontend ne
 * connaît pas est une réponse qu'il ne comprend pas, jamais converti par `as`.
 */
export const ADMIN_MCP_CLIENT_REGISTRATIONS = ['preregistered', 'cimd'] as const

export type AdminMcpClientRegistration = (typeof ADMIN_MCP_CLIENT_REGISTRATIONS)[number]

/**
 * Un client MCP vu du backoffice (`GET /api/backoffice/mcp/clients`, #652,
 * spec 0007 T13), reflet exact de `BackofficeMcpClientResource`.
 */
export interface AdminMcpClient {
  /**
   * UUID de la ligne `mcp_client`, l'identifiant des `PUT` et `DELETE`. Ce
   * n'est **pas** `clientId` : ce dernier peut être une URL (client CIMD),
   * impropre à un segment de chemin.
   */
  readonly id: string
  /** L'identifiant public du client, celui que voient les connexions et les consentements. */
  readonly clientId: string
  readonly name: string
  readonly redirectUris: readonly string[]
  readonly registration: AdminMcpClientRegistration
  /** Instant au format ATOM, tel que le serveur l'écrit. */
  readonly registeredAt: string
  /**
   * Connexions actives de ce client, comptées par le serveur. Vaut `0` dans la
   * réponse d'un enregistrement (un client neuf n'en a aucune) ; la réponse
   * d'une révision porte le vrai compte.
   */
  readonly activeConnections: number
}
