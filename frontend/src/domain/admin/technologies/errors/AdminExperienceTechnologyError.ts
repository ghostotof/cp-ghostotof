import type { SessionEndedReason } from '../../shared/errors/sessionEnded'

/**
 * `reason` catégorise l'échec pour que la présentation choisisse le bon
 * message traduit (cf. i18n `admin.technologies.errors.*`) sans avoir à
 * connaître le détail du transport HTTP ; `message` reste le détail brut
 * (souvent déjà en français côté backend) utile pour le débogage/logs.
 *
 * `session-ended` (#748) : produite par le dépôt sur un 401, ou un 403 à une écriture partie sans
 * jeton CSRF ; jamais affichée, le bandeau de session finie est alors la seule annonce.
 */
export type AdminExperienceTechnologyErrorReason =
  | 'duplicate'
  | 'not-found'
  | 'validation'
  | 'unknown'
  | SessionEndedReason

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
