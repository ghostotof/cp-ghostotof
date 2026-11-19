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
 * Comment le client est connu du serveur :
 * - `preregistered` : enregistré à l'avance par l'administrateur du site
 *   (`app:mcp:client:register`) ;
 * - `cimd` : un document de métadonnées que le client publie à son adresse
 *   (Client ID Metadata Document, #651), lu par le site. Seul le domaine de cette
 *   adresse est vérifié ; le nom est celui que le client se donne.
 */
export type ConsentClientRegistration = 'preregistered' | 'cimd'

export interface ConsentClient {
  id: string
  /**
   * Le nom du client. Pour un client `preregistered`, celui que l'administrateur
   * du site a saisi à l'enregistrement. Pour un client `cimd`, celui que le client
   * se donne lui-même : le site ne l'a pas vérifié, la page le dit « déclaré » et
   * met le `domain` en évidence.
   */
  name: string
  registration: ConsentClientRegistration
  /**
   * Le domaine où le site a lu la déclaration du client : la seule information
   * vérifiée d'un client `cimd`. Chaîne non vide si et seulement si `cimd`, `null`
   * pour un client `preregistered` (le contrat de la Task 10).
   */
  domain: string | null
}

/**
 * Le seul scope qu'une connexion MCP peut demander (spec 0007 phase 2) : la
 * lecture du corpus nominatif. Le texte de la page, qui dit ce que la connexion
 * ouvre, est écrit pour lui seul ; un résumé portant un autre scope est refusé
 * plutôt qu'affiché sous un texte qui ne le décrirait pas.
 */
export const NOMINATIVE_READ_SCOPE = 'nominative:read'

/**
 * Le compte au nom duquel la connexion serait accordée (#650) : celui dont le
 * cookie arrive avec la requête du résumé, lu côté serveur. Jamais un état du
 * frontend — un autre onglet a pu changer de compte depuis — : la page ne fait
 * que l'afficher, pour que la personne voie, avant de décider, qui accorde.
 */
export interface ConsentAccount {
  /**
   * L'UUID du compte, tel que le serveur le connaît. La décision le renvoie : le
   * serveur n'accorde (ni ne refuse) qu'au compte que cette page a nommé, et
   * répond 409 si le cookie est devenu celui d'un autre (F5). Jamais calculé ici.
   */
  id: string
  /** L'identifiant de connexion de la personne, tel que le serveur le connaît. */
  username: string
}

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
  account: ConsentAccount
}
