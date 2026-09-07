/**
 * Levée quand le journal des incidents ne peut pas être récupéré. Erreur de
 * domaine plutôt qu'une exception HTTP brute : l'application n'a pas à
 * connaître le code de statut pour décider d'afficher son état d'erreur.
 */
export class IncidentsUnavailableError extends Error {
  constructor() {
    super('Les incidents ne sont pas disponibles.')
    this.name = 'IncidentsUnavailableError'
  }
}
