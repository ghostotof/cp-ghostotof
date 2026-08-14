/**
 * Levée par QualityContentRepository.get() lorsque le backend ne peut pas servir le contenu
 * (indisponibilité, erreur serveur...), pour que la présentation puisse afficher un
 * message générique sans connaître le détail du transport HTTP.
 */
export class QualityContentUnavailableError extends Error {
  constructor() {
    super('Quality content unavailable')
    this.name = 'QualityContentUnavailableError'
  }
}
