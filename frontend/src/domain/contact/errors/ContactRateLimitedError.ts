/**
 * Levée par ContactRepository.submit() sur un 429 : la limitation de débit de
 * l'API a refusé l'envoi. Cas distinct d'un échec générique parce que la
 * consigne au visiteur n'est pas la même — attendre quelques minutes suffit,
 * il n'a rien à corriger.
 *
 * Le limiteur **applicatif** Symfony (`contact_form`) et la zone nginx
 * `contact` mènent tous deux ici : en préprod et en prod le front partage
 * l'origine de l'API, le 429 de nginx est donc lisible malgré l'absence
 * d'en-têtes CORS (#368). Le quota applicatif, le plus bas, tombe le premier.
 * En dev seulement (Vite et nginx sur deux ports), le navigateur rejette le
 * 429 de nginx et le cas retombe sur ContactSubmissionFailedError.
 */
export class ContactRateLimitedError extends Error {
  constructor() {
    super('Contact submission rate limited')
    this.name = 'ContactRateLimitedError'
  }
}
