/**
 * La demande d'autorisation est invalide, expirée ou a été altérée : le serveur
 * l'a refusée à la revalidation (400 problem+json, type
 * `/errors/authorization-request-rejected`), ou la page n'a pas pu la lire.
 * État terminal : il n'y a rien à réessayer ici, la personne recommence depuis
 * son assistant.
 */
export class InvalidConsentRequestError extends Error {
  constructor(message = 'The authorization request is invalid or has expired') {
    super(message)
    this.name = 'InvalidConsentRequestError'
  }
}
