/**
 * `reason` catégorise l'échec pour que la présentation choisisse le bon
 * message traduit (cf. i18n `admin.users.errors.*`) sans connaître le détail du
 * transport HTTP. Plusieurs cas de conflit (409) coexistent, désambiguïsés par
 * l'opération et le `detail` renvoyé :
 * - `email-taken` : invitation d'une adresse déjà rattachée à un compte ;
 * - `cannot-modify-own-roles` / `cannot-demote-last-super` : garde-fous sur le
 *   changement de rôle ;
 * - `already-activated` : renvoi d'invitation à un compte déjà activé ;
 * - `cannot-delete-self` : suppression de son propre compte (l'UI désactive
 *   déjà le bouton pour sa ligne, gardé ici en défense en profondeur).
 */
export type AdminUserErrorReason =
  | 'not-found'
  | 'cannot-delete-self'
  | 'email-taken'
  | 'email-invalid'
  | 'cannot-modify-own-roles'
  | 'cannot-demote-last-super'
  | 'already-activated'
  | 'validation'
  | 'unknown'

/**
 * Levée par AdminUserRepository en cas d'échec d'une opération.
 */
export class AdminUserError extends Error {
  readonly reason: AdminUserErrorReason

  constructor(reason: AdminUserErrorReason, message: string) {
    super(message)
    this.name = 'AdminUserError'
    this.reason = reason
  }
}
