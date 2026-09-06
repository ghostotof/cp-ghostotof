/**
 * Levée quand la liste des contributions ne peut pas être récupérée. Erreur de
 * domaine plutôt qu'une exception HTTP brute : l'application n'a pas à
 * connaître le code de statut pour décider d'afficher son état d'erreur.
 */
export class ContributionsUnavailableError extends Error {
  constructor() {
    super('Les contributions ne sont pas disponibles.')
    this.name = 'ContributionsUnavailableError'
  }
}
