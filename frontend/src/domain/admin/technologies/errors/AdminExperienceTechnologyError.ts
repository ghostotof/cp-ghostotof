/**
 * `reason` catégorise l'échec pour que la présentation choisisse le bon
 * message traduit (cf. i18n `admin.technologies.errors.*`) sans avoir à
 * connaître le détail du transport HTTP ; `message` reste le détail brut
 * (souvent déjà en français côté backend) utile pour le débogage/logs.
 */
export type AdminExperienceTechnologyErrorReason = 'duplicate' | 'not-found' | 'validation' | 'unknown'

/**
 * Levée par AdminExperienceTechnologyRepository en cas d'échec d'une
 * opération CRUD (validation, doublon, id inconnu, indisponibilité).
 */
export class AdminExperienceTechnologyError extends Error {
  readonly reason: AdminExperienceTechnologyErrorReason

  constructor(reason: AdminExperienceTechnologyErrorReason, message: string) {
    super(message)
    this.name = 'AdminExperienceTechnologyError'
    this.reason = reason
  }
}
