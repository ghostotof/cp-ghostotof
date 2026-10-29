import type { LocationQuery, RouteLocationNormalizedLoaded, RouteLocationRaw } from 'vue-router'
import type { Locale } from '../../domain/portfolio/entities/Locale'
import { routeLocale } from './routeLocale'

/**
 * Le drapeau qui demande à la page de connexion de s'ouvrir pour une personne
 * déjà connectée (#647) : le consentement MCP l'exige quand le serveur refuse
 * d'accorder une connexion sur une authentification de plus de dix minutes
 * (`reauthentication-required`). Écrit une fois, lu par la route de connexion
 * (son `beforeEnter`), par LoginPage (son annonce) et posé par
 * `switchAccountLocation`, son seul constructeur.
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

/**
 * Le lien « Changer de compte » / « Se reconnecter pour autoriser » (#748, R6, Std3) : la connexion rouverte pour
 * une personne déjà connectée (`reauth=1`), dans la langue de la route (`fallback` sans elle), avec le retour à
 * la même demande (`redirect` = le chemin complet). Même forme que `signInAgainLocation`, un autre drapeau.
 */
export function switchAccountLocation(
  route: Pick<RouteLocationNormalizedLoaded, 'fullPath' | 'params'>,
  fallback: Locale,
): RouteLocationRaw {
  return {
    name: 'login',
    params: { locale: routeLocale(route, fallback) },
    query: { redirect: route.fullPath, [REAUTH_QUERY]: REAUTH_VALUE },
  }
}
