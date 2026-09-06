/**
 * `reason` catégorise l'échec pour que la présentation choisisse le bon
 * message traduit (cf. i18n `admin.contributions.errors.*`) sans connaître le
 * détail du transport HTTP ; `message` garde le détail brut, utile au
 * débogage.
 */
export type AdminContributionErrorReason = 'not-found' | 'validation' | 'unknown'

/**
 * Levée par AdminContributionRepository en cas d'échec d'une opération CRUD
 * (validation, id inconnu, indisponibilité).
 */
export class AdminContributionError extends Error {
  readonly reason: AdminContributionErrorReason

  constructor(reason: AdminContributionErrorReason, message: string) {
    super(message)
    this.name = 'AdminContributionError'
    this.reason = reason
  }
}
