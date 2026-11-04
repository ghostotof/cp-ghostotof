import type { LocationQuery, RouteLocationNormalizedLoaded, RouteLocationRaw } from 'vue-router'
import type { Locale } from '../../domain/portfolio/entities/Locale'
import { loginLocation, REAUTH_QUERY, REAUTH_VALUE } from './loginLocation'

/**
 * Le drapeau qui demande à la page de connexion de s'ouvrir pour une personne
 * déjà connectée (#647) : le consentement MCP l'exige quand le serveur refuse
 * d'accorder une connexion sur une authentification de plus de dix minutes
 * (`reauthentication-required`), et « Changer de compte » le pose après un refus
 * réel (un 403 qui n'est pas une session finie : le consentement, « Mes connexions MCP », l'assistant).
 * Écrit une fois, lu par la route de connexion (son `beforeEnter`), par LoginPage (son annonce) et posé par
 * `loginLocation`, via `switchAccountLocation`.
 *
 * Il n'ouvre que la page : la cible du retour (`redirect`) reste jugée par
 * LoginPage, un chemin interne seulement — la garde contre la redirection
 * ouverte ne dépend pas de lui. Une seule valeur, `1` : tout autre ne compte
 * pas. Les constantes du drapeau sont déclarées dans `loginLocation.ts` (St3-9, aucun cycle d'import).
 */
export function isReauthentication(location: { query: LocationQuery }): boolean {
  return REAUTH_VALUE === location.query[REAUTH_QUERY]
}

/**
 * Le lien « Changer de compte » / « Se reconnecter pour autoriser » (#748, R6, Std3) : `loginLocation` avec le
 * drapeau `reauth=1`, la connexion rouverte pour une personne déjà connectée. Même forme que `signInAgainLocation`,
 * un autre drapeau.
 */
export function switchAccountLocation(
  route: Pick<RouteLocationNormalizedLoaded, 'fullPath' | 'params' | 'meta'>,
  fallback: Locale,
): RouteLocationRaw {
  return loginLocation(route, fallback, 'reauthentication')
}
