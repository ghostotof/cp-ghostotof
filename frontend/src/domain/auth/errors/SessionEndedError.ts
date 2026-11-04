/**
 * La session de la personne a pris fin pendant un appel : un 401, ou un 403 à une écriture partie
 * sans jeton CSRF (#748). Ce n'est pas une erreur du contexte qui l'a vue passer (administration,
 * connexions MCP, consentement) : elle sort de leur hiérarchie, et la règle qui la décide est écrite
 * une fois, dans `infrastructure/http/sessionAwareFetch.ts`.
 */
export class SessionEndedError extends Error {
  constructor() {
    super('The session has ended')
    this.name = 'SessionEndedError'
  }
}
