/**
 * `reason` catégorise l'échec pour que la présentation choisisse le bon
 * message traduit (cf. i18n `admin.about.errors.*`) sans avoir à connaître le
 * détail du transport HTTP. Partagée entre settings, site-cards et me-cards
 * (même bounded context, mêmes types d'erreur possibles).
 *
 * Les deux derniers motifs viennent de la spec 0004 : `translation-already-exists`
 * (409) quand le groupe visé porte déjà cette langue, `unknown-translation-group`
 * (422) quand il a disparu du périmètre — pour une carte « moi », c'est aussi
 * la réponse à un rattachement qui traverserait les catégories. L'échec de
 * l'endpoint d'ordre, lui, n'est jamais une `AdminAboutError` : il lève une
 * `AdminOrderError`, seul type que `useOrderDraft` sait interpréter.
 */
export type AdminAboutErrorReason =
  | 'not-found'
  | 'validation'
  | 'unknown'
  | 'translation-already-exists'
  | 'unknown-translation-group'

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
