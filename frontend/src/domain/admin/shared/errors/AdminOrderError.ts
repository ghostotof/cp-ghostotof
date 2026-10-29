import type { SessionEndedReason } from './sessionEnded'

/**
 * `reason` catégorise l'échec d'un `PUT …/order` pour que `useOrderDraft`
 * choisisse le comportement à tenir sans connaître le transport :
 * `stale-order` (422 `unknown-order-entry` ou `incomplete-order` — la liste
 * a changé entre le chargement et l'enregistrement, D4) déclenche un
 * rechargement automatique ; `unknown` laisse le brouillon en l'état pour
 * que l'admin puisse réessayer.
 *
 * `session-ended` (#748) : produite par le dépôt sur un 401, ou un 403 à une écriture partie sans
 * jeton CSRF ; jamais affichée, le bandeau de session finie est alors la seule annonce.
 */
export type AdminOrderErrorReason =
  | 'stale-order'
  | 'unknown'
  | SessionEndedReason

export class AdminOrderError extends Error {
  readonly reason: AdminOrderErrorReason

  constructor(reason: AdminOrderErrorReason, message: string) {
    super(message)
    this.name = 'AdminOrderError'
    this.reason = reason
  }
}
