/**
 * Tout autre échec de l'échange de consentement : réseau coupé, 5xx, limite de
 * débit, 422 (corps mal formé — un défaut de la page, pas de la personne),
 * réponse 200 qui n'a pas la forme attendue. Le détail n'est pas exposé : la
 * page n'a qu'un message, « réessayez ».
 */
export class ConsentFailedError extends Error {
  constructor(message = 'The consent exchange failed') {
    super(message)
    this.name = 'ConsentFailedError'
  }
}
