/**
 * `reason` catégorise l'échec pour que la présentation choisisse le message
 * traduit (cf. i18n `admin.translation.errors.*`) sans connaître le transport :
 * `validation` (422), `rate-limited` (429, quota du compte), `unavailable`
 * (503, fournisseur en échec ou réponse hors schéma), `unknown` (le reste).
 */
export type AdminTranslationErrorReason = 'validation' | 'rate-limited' | 'unavailable' | 'unknown'

export class AdminTranslationError extends Error {
  readonly reason: AdminTranslationErrorReason

  constructor(reason: AdminTranslationErrorReason, message: string) {
    super(message)
    this.name = 'AdminTranslationError'
    this.reason = reason
  }
}
