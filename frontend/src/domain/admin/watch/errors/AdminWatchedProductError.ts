/**
 * `reason` catégorise l'échec pour que la présentation choisisse le bon message
 * traduit (cf. i18n `admin.watch.errors.*`) sans connaître le transport.
 *
 * `conflict` n'existe pas dans les autres slices d'administration : il couvre
 * les deux refus propres à ce contexte — un slug déjà suivi, et une tentative
 * de changer le slug d'une entrée existante.
 */
export type AdminWatchedProductErrorReason = 'not-found' | 'validation' | 'conflict' | 'unknown'

export class AdminWatchedProductError extends Error {
  readonly reason: AdminWatchedProductErrorReason

  constructor(reason: AdminWatchedProductErrorReason, message: string) {
    super(message)
    this.name = 'AdminWatchedProductError'
    this.reason = reason
  }
}
