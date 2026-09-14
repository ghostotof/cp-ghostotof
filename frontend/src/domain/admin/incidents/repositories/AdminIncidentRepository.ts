import type { AdminIncident } from '../entities/AdminIncident'

export interface AdminIncidentInput {
  locale: string
  title: string
  version: string
  occurredAt: string
  impact: string
  rootCause: string
  resolution: string
  invariant: string
  position: number
}

/**
 * Abstraction (DIP) dont dépend l'application. Distincte d'IncidentRepository
 * (lecture publique seule) : celle-ci couvre le CRUD réservé au backoffice.
 */
export interface AdminIncidentRepository {
  list(): Promise<readonly AdminIncident[]>

  create(input: AdminIncidentInput): Promise<AdminIncident>

  update(id: string, input: AdminIncidentInput): Promise<AdminIncident>

  remove(id: string): Promise<void>
}
