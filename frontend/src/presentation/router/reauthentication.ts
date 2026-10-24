import type { LocationQuery } from 'vue-router'

/**
 * Le drapeau qui demande à la page de connexion de s'ouvrir pour une personne
 * déjà connectée (#647) : le consentement MCP l'exige quand le serveur refuse
 * d'accorder une connexion sur une authentification de plus de dix minutes
 * (`reauthentication-required`). Écrit une fois, lu par la route de connexion
 * (son `beforeEnter`), par LoginPage (son annonce) et posé par
 * OAuthConsentPage.
 *
 * Il n'ouvre que la page : la cible du retour (`redirect`) reste jugée par
 * LoginPage, un chemin interne seulement — la garde contre la redirection
 * ouverte ne dépend pas de lui. Une seule valeur, `1` : tout autre ne compte
 * pas.
 */
export const REAUTH_QUERY = 'reauth'
export const REAUTH_VALUE = '1'

export function isReauthentication(location: { query: LocationQuery }): boolean {
  return REAUTH_VALUE === location.query[REAUTH_QUERY]
}
