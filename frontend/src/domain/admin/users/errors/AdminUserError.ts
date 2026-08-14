/**
 * `reason` catégorise l'échec pour que la présentation choisisse le bon
 * message traduit (cf. i18n `admin.users.errors.*`) sans avoir à connaître le
 * détail du transport HTTP. `cannot-delete-self` est spécifique à ce module :
 * le backend refuse (409) qu'un ROLE_SUPER supprime son propre compte (cf.
 * CannotDeleteOwnAccountException) — l'UI désactive déjà le bouton pour sa
 * propre ligne, mais ce cas reste géré ici en défense en profondeur (état
 * désynchronisé possible avec plusieurs onglets ouverts).
 */
export type AdminUserErrorReason = 'not-found' | 'cannot-delete-self' | 'validation' | 'unknown'

/**
 * Levée par AdminUserRepository en cas d'échec d'une opération (suppression,
 * changement de mot de passe).
 */
export class AdminUserError extends Error {
  readonly reason: AdminUserErrorReason

  constructor(reason: AdminUserErrorReason, message: string) {
    super(message)
    this.name = 'AdminUserError'
    this.reason = reason
  }
}
