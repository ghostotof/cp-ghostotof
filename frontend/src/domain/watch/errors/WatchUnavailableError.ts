/**
 * Levée quand l'état de la veille ne peut pas être récupéré. Erreur de domaine
 * plutôt qu'une exception HTTP brute : l'application n'a pas à connaître le
 * code de statut pour décider d'afficher son état d'erreur.
 */
export class WatchUnavailableError extends Error {
  constructor() {
    super("L'état de la veille technique n'est pas disponible.")
    this.name = 'WatchUnavailableError'
  }
}
