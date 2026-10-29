import type { SessionEndedReason } from '../../shared/errors/sessionEnded'

/**
 * `reason` catégorise l'échec pour que la présentation choisisse le bon message
 * traduit (cf. i18n `admin.watch.errors.*`) sans connaître le transport.
 *
 * `conflict` n'existe pas dans les autres slices d'administration : il couvre
 * les deux refus propres à ce contexte — un slug déjà suivi, et une tentative
 * de changer le slug d'une entrée existante.
 *
 * `session-ended` (#748) : produite par le dépôt sur un 401, ou un 403 à une écriture partie sans
 * jeton CSRF ; jamais affichée, le bandeau de session finie est alors la seule annonce.
 */
export type AdminWatchedProductErrorReason =
  | 'not-found'
  | 'validation'
  | 'conflict'
  | 'unknown'
  | SessionEndedReason

export class AdminWatchedProductError extends Error {
  readonly reason: AdminWatchedProductErrorReason

  constructor(reason: AdminWatchedProductErrorReason, message: string) {
    super(message)
    this.name = 'AdminWatchedProductError'
    this.reason = reason
  }
}
