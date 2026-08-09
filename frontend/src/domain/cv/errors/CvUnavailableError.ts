/**
 * Levée par CvRepository.download() lorsque le backend ne peut pas servir le
 * fichier (session expirée, CV non déployé sur l'environnement...), pour que
 * la présentation puisse afficher un message générique sans connaître le
 * détail du transport HTTP (401, 404...).
 */
export class CvUnavailableError extends Error {
  constructor() {
    super('CV unavailable')
    this.name = 'CvUnavailableError'
  }
}
