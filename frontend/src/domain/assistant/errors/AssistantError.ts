/**
 * Catégorise l'échec pour que la présentation choisisse le message traduit sans
 * connaître le transport : `unauthenticated` (401), `forbidden` (403),
 * `validation` (422, conversation hors bornes), `too-large` (413),
 * `rate-limited` (429), `unavailable` (503 ou événement `error` du flux),
 * `network` (réseau coupé, flux interrompu avant la fin), `unknown` (le reste).
 */
export type AssistantErrorReason =
  | 'unauthenticated'
  | 'forbidden'
  | 'validation'
  | 'too-large'
  | 'rate-limited'
  | 'unavailable'
  | 'network'
  | 'unknown'

export class AssistantError extends Error {
  readonly reason: AssistantErrorReason
  readonly retryAfterSeconds: number | null

  /**
   * @param retryAfterSeconds délai avant de réessayer, renseigné sur un 429 quand
   *   le serveur le fournit (absent sur la zone nginx) ; `null` sinon.
   */
  constructor(reason: AssistantErrorReason, retryAfterSeconds: number | null = null) {
    super(`Assistant error: ${reason}`)
    this.name = 'AssistantError'
    this.reason = reason
    this.retryAfterSeconds = retryAfterSeconds
  }
}
