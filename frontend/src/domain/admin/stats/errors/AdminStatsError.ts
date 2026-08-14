/**
 * `reason` catégorise l'échec pour que la présentation choisisse le bon
 * message traduit (cf. i18n `admin.stats.errors.*`) sans avoir à connaître le
 * détail du transport HTTP ; `message` reste le détail brut, utile pour le
 * débogage/logs.
 */
export type AdminStatsErrorReason = 'not-found' | 'validation' | 'unknown'

/**
 * Levée par AdminStatsRepository en cas d'échec d'une opération CRUD
 * (validation, id inconnu, indisponibilité).
 */
export class AdminStatsError extends Error {
  readonly reason: AdminStatsErrorReason

  constructor(reason: AdminStatsErrorReason, message: string) {
    super(message)
    this.name = 'AdminStatsError'
    this.reason = reason
  }
}
