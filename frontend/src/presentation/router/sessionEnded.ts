import type { LocationQuery, RouteLocationNormalizedLoaded, RouteLocationRaw } from 'vue-router'
import type { Locale } from '../../domain/portfolio/entities/Locale'
import { routeLocale } from './routeLocale'

/**
 * Le drapeau qui dit à la page de connexion qu'elle s'ouvre parce que la
 * session de la personne a pris fin (#650, R5a) : le serveur a refusé sa
 * session (un 401 du backoffice, que `SessionEndedBanner` annonce, ou celui
 * d'une page de la personne comme « Mes connexions MCP »). Écrit une fois, posé
 * par `signInAgainLocation` (le seul constructeur de ces liens), lu par LoginPage, qui annonce pourquoi elle s'ouvre —
 * sans lui, la personne arrive sur un formulaire de connexion sans savoir
 * qu'elle y a été menée.
 *
 * Il n'ouvre rien et ne donne aucun droit : il n'est qu'une annonce. La cible du
 * retour (`redirect`) reste jugée par LoginPage, un chemin interne seulement.
 * Une seule valeur, `1` : tout autre ne compte pas (même règle que `reauth`).
 */
export const SESSION_ENDED_QUERY = 'expired'
export const SESSION_ENDED_VALUE = '1'

export function isSessionEnded(location: { query: LocationQuery }): boolean {
  return SESSION_ENDED_VALUE === location.query[SESSION_ENDED_QUERY]
}

/**
 * Le lien de reconnexion d'une session finie (#748, R6, Std3) : la connexion, dans la langue de la route
 * (`fallback`, la langue i18n, quand elle n'en a pas), avec le retour à la page courante (`redirect`, le
 * chemin complet — requête et fragment compris — jugé par LoginPage : un chemin interne seulement) et
 * l'annonce qu'elle s'ouvre parce que la session a pris fin.
 *
 * Le seul constructeur : le bandeau, l'en-tête, « Mes connexions MCP », l'assistant et le consentement
 * l'appellent, aucun ne compose ce lien à la main. Le lien s'ouvre dans un nouvel onglet (`SignInAgainLink`)
 * sauf sur le consentement (une étape d'un flux OAuth, `.claude/rules/frontend-session.md`).
 */
export function signInAgainLocation(
  route: Pick<RouteLocationNormalizedLoaded, 'fullPath' | 'params'>,
  fallback: Locale,
): RouteLocationRaw {
  return {
    name: 'login',
    params: { locale: routeLocale(route, fallback) },
    query: { redirect: route.fullPath, [SESSION_ENDED_QUERY]: SESSION_ENDED_VALUE },
  }
}
