/**
 * Le serveur a refusé le corps de la décision (422) : un champ trop long, un
 * `approved` absent. La page n'envoie que ce qu'elle a reçu plus un booléen,
 * c'est donc un défaut de la page, pas de la personne — réessayer renverrait le
 * même corps. État terminal, à la différence de ConsentFailedError.
 */
export class MalformedConsentDecisionError extends Error {
  constructor(message = 'The server refused the consent decision body') {
    super(message)
    this.name = 'MalformedConsentDecisionError'
  }
}
