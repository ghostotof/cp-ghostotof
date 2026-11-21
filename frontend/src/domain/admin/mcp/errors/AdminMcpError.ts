/**
 * `reason` catégorise l'échec pour que la présentation choisisse le bon
 * message traduit (cf. i18n `admin.mcp.errors.*`) sans connaître le détail du
 * transport HTTP :
 * - `not-found` : client inconnu (404), supprimé depuis la lecture de la liste ;
 * - `client-id-taken` : enregistrement d'un identifiant déjà pris (409 sans
 *   type de problème) ;
 * - `declared-by-document` : révision d'un client CIMD, dont le document fait
 *   foi (409 de type `client-declared-by-document`, distingué de l'autre 409
 *   par `hasProblemType`, jamais par le `detail` localisé) ;
 * - `validation` : saisie refusée (422), champ par champ par le serveur ;
 * - `unknown` : tout le reste, corps refusé (400) compris.
 */
export type AdminMcpErrorReason =
  | 'not-found'
  | 'client-id-taken'
  | 'declared-by-document'
  | 'validation'
  | 'unknown'

/**
 * Levée par AdminMcpRepository en cas d'échec d'une opération.
 */
export class AdminMcpError extends Error {
  readonly reason: AdminMcpErrorReason

  constructor(reason: AdminMcpErrorReason, message: string) {
    super(message)
    this.name = 'AdminMcpError'
    this.reason = reason
  }
}
