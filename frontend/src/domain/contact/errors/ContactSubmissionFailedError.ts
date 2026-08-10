/**
 * Levée par ContactRepository.submit() lorsque le backend rejette ou échoue à
 * traiter la soumission (validation, indisponibilité...), pour que la
 * présentation puisse afficher un message générique sans connaître le détail
 * du transport HTTP (422, 5xx...).
 */
export class ContactSubmissionFailedError extends Error {
  constructor() {
    super('Contact submission failed')
    this.name = 'ContactSubmissionFailedError'
  }
}
