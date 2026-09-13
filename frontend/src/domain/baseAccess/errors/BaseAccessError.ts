/**
 * `reason` catégorise l'échec de POST /api/account/base-access pour que la
 * présentation choisisse le bon message traduit sans connaître le détail
 * HTTP — même pattern que PasswordSetupLinkError :
 * - `rate-limited` : quota IP dépassé (ADR 0003 D6, 20/heure) — 429 ;
 * - `unknown`      : autre échec (réseau, 5xx…).
 */
export type BaseAccessErrorReason = 'rate-limited' | 'unknown'

export class BaseAccessError extends Error {
  readonly reason: BaseAccessErrorReason

  constructor(reason: BaseAccessErrorReason, message: string) {
    super(message)
    this.name = 'BaseAccessError'
    this.reason = reason
  }
}
