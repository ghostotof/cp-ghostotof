/**
 * `reason` catégorise l'échec pour que la présentation choisisse le bon
 * message traduit (cf. i18n `admin.about.errors.*`) sans avoir à connaître le
 * détail du transport HTTP. Partagée entre settings, site-cards et me-cards
 * (même bounded context, mêmes types d'erreur possibles).
 */
export type AdminAboutErrorReason = 'not-found' | 'validation' | 'unknown'

/**
 * Levée par les repositories admin About en cas d'échec d'une opération CRUD
 * (validation, id/locale inconnu, indisponibilité).
 */
export class AdminAboutError extends Error {
  readonly reason: AdminAboutErrorReason

  constructor(reason: AdminAboutErrorReason, message: string) {
    super(message)
    this.name = 'AdminAboutError'
    this.reason = reason
  }
}
