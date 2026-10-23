/**
 * Lecture de la demande d'autorisation posée sur la page de consentement (#647),
 * et garde de la redirection finale vers le client.
 *
 * Fonctions pures, sans dépendance au routeur ni au navigateur : la page leur
 * passe `route.query` tel quel (un objet de chaînes), ce qui les rend testables
 * sans jsdom et garde le domaine indépendant de la présentation.
 */
import type { ConsentParams } from '../entities/ConsentRequest'

/**
 * Les paramètres de la demande et leur nom dans la requête (snake_case, celui
 * d'OAuth et du point d'autorisation). Seules ces clés passent : une autre clé
 * de la requête n'est pas renvoyée au serveur.
 */
const QUERY_KEYS: Readonly<Record<keyof ConsentParams, string>> = {
  responseType: 'response_type',
  clientId: 'client_id',
  redirectUri: 'redirect_uri',
  scope: 'scope',
  state: 'state',
  codeChallenge: 'code_challenge',
  codeChallengeMethod: 'code_challenge_method',
  resource: 'resource',
}

const PARAM_NAMES = Object.keys(QUERY_KEYS) as (keyof ConsentParams)[]

/**
 * La demande reçue, en camelCase, ou `null` si la requête est illisible.
 *
 * Illisible : un paramètre répété (`?state=a&state=b`, un tableau pour le
 * routeur) ou sans valeur (`?state`, `null`). Choisir l'une des valeurs, ou
 * en inventer une vide, reviendrait à envoyer au serveur une demande que le
 * client n'a pas faite ; le point d'autorisation n'en produit jamais de telle,
 * la page la traite donc comme une demande invalide, sans appel.
 *
 * Un paramètre absent reste `null`, une valeur vide reste vide : c'est au
 * serveur de les refuser, pas à la page de les compléter.
 */
export function consentParamsFromQuery(query: Readonly<Record<string, unknown>>): ConsentParams | null {
  const params = {} as ConsentParams

  for (const name of PARAM_NAMES) {
    const value = query[QUERY_KEYS[name]]
    if (undefined === value) {
      params[name] = null
    } else if ('string' === typeof value) {
      params[name] = value
    } else {
      return null
    }
  }

  return params
}

/** La demande remise en requête snake_case (pour le `GET` du résumé), sans les paramètres absents. */
export function consentQuery(params: ConsentParams): Record<string, string> {
  const query: Record<string, string> = {}

  for (const name of PARAM_NAMES) {
    const value = params[name]
    if (null !== value) {
      query[QUERY_KEYS[name]] = value
    }
  }

  return query
}

/**
 * Vrai si la page peut suivre cette URL de retour : `http:` ou `https:`
 * seulement.
 *
 * L'URL vient du serveur, qui la construit à partir de la redirection
 * enregistrée du client ; la page la suit par `window.location.assign`. Un
 * `javascript:` ou un `data:` y exécuterait du code dans l'origine du site — la
 * session de la personne avec. Garde de défense en profondeur, donc, qui ne
 * présume pas de ce que le serveur admet à l'enregistrement. Un schéma propre à
 * une application (`cursor://`…) est refusé aussi : la consigne est stricte.
 */
export function isNavigableRedirect(url: string): boolean {
  let protocol: string
  try {
    protocol = new URL(url).protocol
  } catch {
    // Relative ou mal formée : rien que la page sache suivre hors de son origine.
    return false
  }

  return 'http:' === protocol || 'https:' === protocol
}
