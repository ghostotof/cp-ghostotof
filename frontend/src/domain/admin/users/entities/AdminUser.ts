/**
 * `status` reflète le cycle d'invitation (cf. backend CpgUser) :
 * - `pending` : invité depuis le backoffice, mot de passe pas encore défini ;
 * - `active`  : compte utilisable (mot de passe défini, ou compte créé en CLI).
 *
 * `email` n'est renseigné que pour les comptes créés par invitation.
 */
export interface AdminUser {
  readonly id: number
  readonly username: string
  readonly email: string | null
  readonly roles: readonly string[]
  readonly status: 'pending' | 'active'
}
