import type { LocationQuery } from 'vue-router'

/**
 * Le drapeau qui dit à la page de connexion qu'elle s'ouvre parce que la
 * session de la personne a pris fin (#650, R5a) : le serveur a refusé sa
 * session (un 401 du backoffice, voir `backofficeSessionExpiry.ts`, ou celui
 * d'une page de la personne comme « Mes connexions MCP »). Écrit une fois, posé
 * par ces deux sites, lu par LoginPage, qui annonce pourquoi elle s'ouvre —
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
