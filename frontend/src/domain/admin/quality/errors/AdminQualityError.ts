/**
 * `reason` catégorise l'échec pour que la présentation choisisse le bon
 * message traduit (cf. i18n `admin.quality.errors.*`) sans avoir à connaître
 * le détail du transport HTTP. Partagée entre principles et traits (même
 * bounded context, mêmes types d'erreur possibles).
 */
export type AdminQualityErrorReason = 'not-found' | 'validation' | 'unknown'

/**
 * Levée par AdminQualityPrincipleRepository/AdminQualityTraitRepository en
 * cas d'échec d'une opération CRUD (validation, id inconnu, indisponibilité).
 */
export class AdminQualityError extends Error {
  readonly reason: AdminQualityErrorReason

  constructor(reason: AdminQualityErrorReason, message: string) {
    super(message)
    this.name = 'AdminQualityError'
    this.reason = reason
  }
}
