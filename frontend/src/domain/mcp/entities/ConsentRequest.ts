/**
 * La demande d'autorisation qu'un client MCP a adressée au site (#647, spec 0007
 * phase 2), telle que le point d'autorisation l'a posée sur la page de
 * consentement, en camelCase.
 *
 * Ce sont les valeurs **reçues**, jamais recalculées : la page les renvoie au
 * serveur, qui les revalide à chaque appel (un paramètre altéré entre-temps est
 * un 400). `null` dit qu'un paramètre était absent de la requête — `state` est
 * facultatif en OAuth, les autres, le serveur seul juge s'ils manquent.
 */
export interface ConsentParams {
  responseType: string | null
  clientId: string | null
  redirectUri: string | null
  scope: string | null
  state: string | null
  codeChallenge: string | null
  codeChallengeMethod: string | null
  resource: string | null
}

/**
 * Comment le client est connu du serveur : enregistré à l'avance par
 * l'administrateur du site (`app:mcp:client:register`), le seul mode qui existe
 * — aucun enregistrement dynamique. Un autre mode aurait son propre texte.
 */
export type ConsentClientRegistration = 'preregistered'

export interface ConsentClient {
  id: string
  /**
   * Le nom que le client s'est **déclaré** à l'enregistrement : le site ne l'a
   * pas vérifié, la page le présente comme tel.
   */
  name: string
  registration: ConsentClientRegistration
}

/** Le résumé de la demande, revalidée par le serveur, que la personne lit avant de décider. */
export interface ConsentRequest {
  client: ConsentClient
  /** L'hôte vers lequel la personne sera renvoyée après sa décision. */
  redirectHost: string
  scope: string
  resource: string
}
