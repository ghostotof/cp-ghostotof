/**
 * La session ne permet pas (ou plus) de consentir : cookie absent ou expiré
 * (401), compte sans le palier de confiance ou jeton CSRF refusé (403). La page
 * marque la session expirée et propose de se reconnecter.
 */
export class SessionExpiredError extends Error {
  constructor(message = 'The session is missing, expired or not trusted') {
    super(message)
    this.name = 'SessionExpiredError'
  }
}
