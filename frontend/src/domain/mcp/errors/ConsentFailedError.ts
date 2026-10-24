/**
 * Tout autre échec de l'échange de consentement : réseau coupé, 5xx, limite de
 * débit, 400 d'un autre `type` que le refus de la demande, réponse 200 qui n'a
 * pas la forme attendue. Le détail n'est pas exposé : la page n'a qu'un
 * message, « réessayez ». Le 422 de la décision a sa propre classe,
 * MalformedConsentDecisionError : réessayer n'y changerait rien.
 */
export class ConsentFailedError extends Error {
  constructor(message = 'The consent exchange failed') {
    super(message)
    this.name = 'ConsentFailedError'
  }
}
