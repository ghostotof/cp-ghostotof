import type { AdminIncident } from '../../../domain/admin/incidents/entities/AdminIncident'
import type {
  AdminIncidentInput,
  AdminIncidentRepository,
} from '../../../domain/admin/incidents/repositories/AdminIncidentRepository'
import {
  AdminIncidentError,
  type AdminIncidentErrorReason,
} from '../../../domain/admin/incidents/errors/AdminIncidentError'
import { BackofficeHttpClient, violationsMessage } from '../shared/BackofficeHttpClient'

interface BackofficeIncidentApiResponse {
  id: number
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

const BASE_PATH = '/api/backoffice/incidents'

/**
 * Implémentation HTTP de AdminIncidentRepository. Toutes les méthodes exigent
 * le cookie httpOnly BEARER, et les mutations le header CSRF
 * (cf. BackofficeHttpClient).
 */
export class HttpAdminIncidentRepository implements AdminIncidentRepository {
  private readonly client: BackofficeHttpClient

  constructor(apiBaseUrl: string) {
    this.client = new BackofficeHttpClient(apiBaseUrl)
  }

  async list(): Promise<readonly AdminIncident[]> {
    const response = await this.client.get(BASE_PATH)

    if (!response.ok) {
      throw await this.toError(response)
    }

    const incidents = (await response.json()) as BackofficeIncidentApiResponse[]

    return incidents.map(this.toEntity)
  }

  async create(input: AdminIncidentInput): Promise<AdminIncident> {
    const response = await this.mutate('POST', BASE_PATH, input)

    return this.toEntity((await response.json()) as BackofficeIncidentApiResponse)
  }

  async update(id: number, input: AdminIncidentInput): Promise<AdminIncident> {
    const response = await this.mutate('PUT', `${BASE_PATH}/${id}`, input)

    return this.toEntity((await response.json()) as BackofficeIncidentApiResponse)
  }

  async remove(id: number): Promise<void> {
    await this.mutate('DELETE', `${BASE_PATH}/${id}`)
  }

  private async mutate(method: string, path: string, body?: unknown): Promise<Response> {
    const response = await this.client.mutate(method, path, body)

    if (!response.ok) {
      throw await this.toError(response)
    }

    return response
  }

  private toEntity(incident: BackofficeIncidentApiResponse): AdminIncident {
    return {
      id: incident.id,
      locale: incident.locale,
      title: incident.title,
      version: incident.version,
      occurredAt: incident.occurredAt,
      impact: incident.impact,
      rootCause: incident.rootCause,
      resolution: incident.resolution,
      invariant: incident.invariant,
      position: incident.position,
    }
  }

  private async toError(response: Response): Promise<AdminIncidentError> {
    const body = await this.client.parseProblem(response)

    if (404 === response.status) {
      return new AdminIncidentError('not-found', 'Incident not found')
    }

    const reason: AdminIncidentErrorReason = 422 === response.status ? 'validation' : 'unknown'

    return new AdminIncidentError(reason, violationsMessage(body))
  }
}
