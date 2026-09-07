/** Forme à plat éditable d'un incident, distincte du contrat public (id, locale, position en plus). */
export interface AdminIncident {
  readonly id: number
  readonly locale: string
  readonly title: string
  readonly version: string
  readonly occurredAt: string
  readonly impact: string
  readonly rootCause: string
  readonly resolution: string
  readonly invariant: string
  readonly position: number
}
