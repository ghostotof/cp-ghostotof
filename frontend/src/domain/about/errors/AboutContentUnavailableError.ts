/**
 * Levée par AboutContentRepository.get() lorsque le backend ne peut pas servir le contenu
 * (indisponibilité, erreur serveur...), pour que la présentation puisse afficher un
 * message générique sans connaître le détail du transport HTTP.
 */
export class AboutContentUnavailableError extends Error {
  constructor() {
    super('About content unavailable')
    this.name = 'AboutContentUnavailableError'
  }
}
