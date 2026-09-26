/**
 * Levée par ContactRepository.submit() sur un 429 : la limitation de débit de
 * l'API a refusé l'envoi. Cas distinct d'un échec générique parce que la
 * consigne au visiteur n'est pas la même — attendre quelques minutes suffit,
 * il n'a rien à corriger.
 *
 * Limite connue et acceptée : en pratique seul le limiteur **applicatif**
 * Symfony (`contact_form`) mène ici. Le 429 de la zone nginx `contact` est
 * émis par nginx sans passer par PHP, donc sans en-têtes CORS ; en production
 * le front est sur une autre origine que l'API, le navigateur rejette la
 * réponse et `fetch` lève un `TypeError` — ce cas retombe sur
 * ContactSubmissionFailedError. Le quota applicatif étant le plus bas, il
 * tombe le premier de toute façon.
 */
export class ContactRateLimitedError extends Error {
  constructor() {
    super('Contact submission rate limited')
    this.name = 'ContactRateLimitedError'
  }
}
