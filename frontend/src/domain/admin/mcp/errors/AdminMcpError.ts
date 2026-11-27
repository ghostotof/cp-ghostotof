/**
 * `reason` catégorise l'échec pour que la présentation choisisse le bon
 * message traduit (cf. i18n `admin.mcpClients.errors.*`) sans connaître le
 * détail du transport HTTP :
 * - `not-found` : client inconnu (404), supprimé depuis la lecture de la liste ;
 * - `client-id-taken` : enregistrement d'un identifiant déjà pris (409 sans
 *   type de problème) ;
 * - `declared-by-document` : révision d'un client CIMD, dont le document fait
 *   foi (409 de type `client-declared-by-document`, distingué de l'autre 409
 *   par `hasProblemType`, jamais par le `detail` localisé) ;
 * - `id-of-verified-domain` : tout 422 de type `client-id-of-verified-domain`
 *   (sans `violations`, distingué des autres 422 par `hasProblemType`). Le plus
 *   souvent, la révision d'un client pré-enregistré qui masque un domaine
 *   vérifié (`AdminMcpClient.shadowsVerifiedDomain`) : il se supprime, il ne se
 *   révise pas (`cannotBeRevised()` côté serveur). Mais l'enregistrement porte
 *   le même type quand le refus échappe au validateur
 *   (`cannotBePreregistered()`), et la page affiche alors le texte écrit pour la
 *   révision — limite connue, ce texte ne convient pas à une création ;
 * - `validation` : tout autre 422, saisie refusée champ par champ par le
 *   serveur (`violations`) — c'est d'ordinaire ainsi que le serveur refuse
 *   l'enregistrement d'un identifiant d'un domaine vérifié : une violation au
 *   champ `clientId`, posée par le validateur avant que la fabrique
 *   `cannotBePreregistered()` ait à servir ;
 * - `unknown` : tout le reste, corps refusé (400) compris.
 */
export type AdminMcpErrorReason =
  | 'not-found'
  | 'client-id-taken'
  | 'declared-by-document'
  | 'id-of-verified-domain'
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
