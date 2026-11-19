/**
 * Le serveur refuse, pour l'instant, de revalider la demande de consentement, et
 * la personne n'y peut rien : réessayer plus tard suffit (type
 * `consent-temporarily-unavailable`, `ConsentTemporarilyUnavailableException`
 * côté backend). Ce n'est ni un refus définitif de la demande
 * (InvalidConsentRequestError) ni l'échec générique (ConsentFailedError) : la
 * demande n'a pas été jugée, rien n'a été accordé ni refusé.
 *
 * Deux émetteurs, au `GET` (affichage) comme au `POST` (décision) :
 * - un 429, quota de revalidation atteint, avec `Retry-After` en secondes ;
 * - un 503, l'hôte du client injoignable, sans délai garanti.
 */
export class ConsentTemporarilyUnavailableError extends Error {
  readonly retryAfterSeconds: number | null

  /**
   * @param retryAfterSeconds délai avant de réessayer, quand le serveur le donne
   *   (le 429) ; `null` sinon (le 503, ou un en-tête absent ou illisible).
   */
  constructor(retryAfterSeconds: number | null = null) {
    super('The consent request is temporarily unavailable')
    this.name = 'ConsentTemporarilyUnavailableError'
    this.retryAfterSeconds = retryAfterSeconds
  }
}
