import type { SessionEndedReason } from '../../shared/errors/sessionEnded'

/**
 * `reason` catégorise l'échec pour que la présentation choisisse le message
 * traduit (cf. i18n `admin.translation.errors.*`) sans connaître le transport :
 * `validation` (422), `rate-limited` (429, quota du compte), `unavailable`
 * (503, fournisseur en échec ou réponse hors schéma), `unknown` (le reste).
 *
 * `session-ended` (#748) : produite par le dépôt sur un 401, ou un 403 à une écriture partie sans
 * jeton CSRF ; jamais affichée, le bandeau de session finie est alors la seule annonce.
 */
export type AdminTranslationErrorReason =
  | 'validation'
  | 'rate-limited'
  | 'unavailable'
  | 'unknown'
  | SessionEndedReason

export class AdminTranslationError extends Error {
  readonly reason: AdminTranslationErrorReason

  constructor(reason: AdminTranslationErrorReason, message: string) {
    super(message)
    this.name = 'AdminTranslationError'
    this.reason = reason
  }
}
