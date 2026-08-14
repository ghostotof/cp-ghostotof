/**
 * Levée par StatsRepository.list() lorsque le backend ne peut pas servir les statistiques
 * (indisponibilité, erreur serveur...), pour que la présentation puisse afficher un
 * message générique sans connaître le détail du transport HTTP.
 */
export class StatsUnavailableError extends Error {
  constructor() {
    super('Stats unavailable')
    this.name = 'StatsUnavailableError'
  }
}
