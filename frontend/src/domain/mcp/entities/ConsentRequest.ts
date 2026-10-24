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
   * Le nom sous lequel le client est connu du site. Pour un client
   * `preregistered`, celui que l'administrateur du site a saisi à
   * l'enregistrement — la page le dit ainsi, sans le donner pour « déclaré par
   * le client ». Un futur mode CIMD (#651) portera un nom déclaré par le client
   * lui-même, et son propre texte.
   */
  name: string
  registration: ConsentClientRegistration
}

/**
 * Le seul scope qu'une connexion MCP peut demander (spec 0007 phase 2) : la
 * lecture du corpus nominatif. Le texte de la page, qui dit ce que la connexion
 * ouvre, est écrit pour lui seul ; un résumé portant un autre scope est refusé
 * plutôt qu'affiché sous un texte qui ne le décrirait pas.
 */
export const NOMINATIVE_READ_SCOPE = 'nominative:read'

/** Le résumé de la demande, revalidée par le serveur, que la personne lit avant de décider. */
export interface ConsentRequest {
  client: ConsentClient
  /**
   * L'hôte vers lequel la personne sera renvoyée après sa décision, sans port
   * (tel que le serveur le lit dans l'URL de redirection enregistrée). La page
   * ne suit l'URL de retour que si elle mène à cet hôte.
   */
  redirectHost: string
  scope: typeof NOMINATIVE_READ_SCOPE
  resource: string
}
