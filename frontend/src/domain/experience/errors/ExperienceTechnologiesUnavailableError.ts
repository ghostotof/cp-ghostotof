/**
 * Levée par ExperienceTechnologyRepository.list() lorsque le backend ne peut pas servir la
 * liste (indisponibilité, erreur serveur...), pour que la présentation puisse afficher un
 * message générique sans connaître le détail du transport HTTP.
 */
export class ExperienceTechnologiesUnavailableError extends Error {
  constructor() {
    super('Experience technologies unavailable')
    this.name = 'ExperienceTechnologiesUnavailableError'
  }
}
