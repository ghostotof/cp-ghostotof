/**
 * Levée par ContactRepository.submit() lorsque l'envoi échoue pour une raison
 * sur laquelle le visiteur ne peut rien : panne réseau, 5xx, réponse
 * inattendue. C'est le seul cas où « réessayez plus tard » est vrai.
 *
 * Les deux refus sur lesquels il peut agir ont leur propre erreur :
 * ContactValidationError (422, saisie à corriger) et ContactRateLimitedError
 * (429, trop d'envois). Ne pas élargir celle-ci pour les couvrir de nouveau —
 * c'est exactement la confusion corrigée par l'issue #236.
 */
export class ContactSubmissionFailedError extends Error {
  constructor() {
    super('Contact submission failed')
    this.name = 'ContactSubmissionFailedError'
  }
}
