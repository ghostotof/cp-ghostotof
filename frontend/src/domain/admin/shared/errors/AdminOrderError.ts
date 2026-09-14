/**
 * `reason` catégorise l'échec d'un `PUT …/order` pour que `useOrderDraft`
 * choisisse le comportement à tenir sans connaître le transport :
 * `stale-order` (422 `unknown-order-entry` ou `incomplete-order` — la liste
 * a changé entre le chargement et l'enregistrement, D4) déclenche un
 * rechargement automatique ; `unknown` laisse le brouillon en l'état pour
 * que l'admin puisse réessayer.
 */
export type AdminOrderErrorReason = 'stale-order' | 'unknown'

export class AdminOrderError extends Error {
  readonly reason: AdminOrderErrorReason

  constructor(reason: AdminOrderErrorReason, message: string) {
    super(message)
    this.name = 'AdminOrderError'
    this.reason = reason
  }
}
