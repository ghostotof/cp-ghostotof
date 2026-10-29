import type { LocationQuery, RouteLocationNormalizedLoaded, RouteLocationRaw } from 'vue-router'
import type { Locale } from '../../domain/portfolio/entities/Locale'
import { loginLocation, SESSION_ENDED_QUERY, SESSION_ENDED_VALUE } from './loginLocation'

/**
 * Le drapeau qui dit à la page de connexion qu'elle s'ouvre parce que la
 * session de la personne a pris fin (#650, R5a) : le serveur a refusé sa
 * session — un 401, ou un 403 à une écriture partie sans jeton CSRF —, de toute page
 * protégée (le backoffice, que `SessionEndedBanner` annonce, « Mes connexions MCP »,
 * l'assistant, le consentement). Écrit une fois, posé par `loginLocation` (le seul
 * constructeur de ces liens, via `signInAgainLocation`), lu par LoginPage, qui annonce pourquoi elle s'ouvre —
 * sans lui, la personne arrive sur un formulaire de connexion sans savoir
 * qu'elle y a été menée.
 *
 * Il n'ouvre que la page, à une personne anonyme comme à une personne encore connectée (B/F2 : un `BEARER` vivant
 * sans `XSRF-TOKEN` laisse `authState.user` posé, et la route de connexion renverrait sinon à l'accueil) ; il ne
 * donne aucun droit. La cible du retour (`redirect`) reste jugée par LoginPage, un chemin interne seulement.
 * Une seule valeur, `1` : tout autre ne compte pas (même règle que `reauth`). Les constantes du drapeau sont déclarées
 * dans `loginLocation.ts` (St3-9, aucun cycle d'import).
 */
export function isSessionEnded(location: { query: LocationQuery }): boolean {
  return SESSION_ENDED_VALUE === location.query[SESSION_ENDED_QUERY]
}

/**
 * Le lien de reconnexion d'une session finie (#748, R6, Std3) : `loginLocation` avec le drapeau `expired=1`.
 *
 * Le bandeau, l'en-tête, « Mes connexions MCP », l'assistant et le consentement l'appellent (par
 * `SignInAgainLink`, sauf le corps du consentement qui en rend lui aussi un), aucun ne compose ce lien à la main.
 * Le lien s'ouvre dans un nouvel onglet sauf sur une route `meta.signInInSameTab` (le consentement, une étape d'un
 * flux OAuth, `.claude/rules/frontend-session.md`).
 */
export function signInAgainLocation(
  route: Pick<RouteLocationNormalizedLoaded, 'fullPath' | 'params' | 'meta'>,
  fallback: Locale,
): RouteLocationRaw {
  return loginLocation(route, fallback, 'session-ended')
}
