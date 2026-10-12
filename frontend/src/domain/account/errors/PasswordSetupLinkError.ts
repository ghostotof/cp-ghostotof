/**
 * `reason` catégorise l'échec du parcours public de définition de mot de passe
 * pour que la présentation choisisse le bon message traduit
 * (cf. i18n `account.setPassword.errors.*`) sans connaître le détail HTTP :
 * - `incomplete`    : jeton absent de l'URL, ou refusé pour sa forme (vide, trop long) — 422 à la
 *                     vérification. Rien que le serveur sache du lien : c'est l'URL qui est abîmée ;
 * - `unusable`      : lien qui ne peut pas servir, quel qu'en soit le motif — 404. Le backend répond
 *                     à l'identique pour un lien inconnu, remplacé, purgé, expiré ou déjà utilisé
 *                     (issue #362) : ne pas chercher à les distinguer ici. 410 compris, que
 *                     renvoyait un backend d'avant #362 (rollback, images désynchronisées) ;
 * - `weak-password` : mot de passe rejeté par le backend — 422 à la soumission ;
 * - `rate-limited`  : trop de tentatives depuis cette adresse IP — 429 ;
 * - `unknown`       : autre échec (réseau, 5xx…).
 */
export type PasswordSetupLinkErrorReason = 'incomplete' | 'unusable' | 'weak-password' | 'rate-limited' | 'unknown'

export class PasswordSetupLinkError extends Error {
  readonly reason: PasswordSetupLinkErrorReason

  constructor(reason: PasswordSetupLinkErrorReason, message: string) {
    super(message)
    this.name = 'PasswordSetupLinkError'
    this.reason = reason
  }
}
