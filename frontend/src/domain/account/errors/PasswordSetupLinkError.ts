/**
 * `reason` catégorise l'échec du parcours public de définition de mot de passe
 * pour que la présentation choisisse le bon message traduit
 * (cf. i18n `account.setPassword.errors.*`) sans connaître le détail HTTP :
 * - `invalid`       : jeton inconnu (lien corrompu) — 404 ;
 * - `expired`       : lien expiré ou déjà utilisé — 410 ;
 * - `weak-password` : mot de passe rejeté par le backend — 422 ;
 * - `rate-limited`  : trop de tentatives depuis cette adresse IP — 429 ;
 * - `unknown`       : autre échec (réseau, 5xx…).
 */
export type PasswordSetupLinkErrorReason = 'invalid' | 'expired' | 'weak-password' | 'rate-limited' | 'unknown'

export class PasswordSetupLinkError extends Error {
  readonly reason: PasswordSetupLinkErrorReason

  constructor(reason: PasswordSetupLinkErrorReason, message: string) {
    super(message)
    this.name = 'PasswordSetupLinkError'
    this.reason = reason
  }
}
