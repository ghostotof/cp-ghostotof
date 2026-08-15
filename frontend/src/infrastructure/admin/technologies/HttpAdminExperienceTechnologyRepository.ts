import type { AdminExperienceTechnology } from '../../../domain/admin/technologies/entities/AdminExperienceTechnology'
import type {
  AdminExperienceTechnologyInput,
  AdminExperienceTechnologyRepository,
} from '../../../domain/admin/technologies/repositories/AdminExperienceTechnologyRepository'
import {
  AdminExperienceTechnologyError,
  type AdminExperienceTechnologyErrorReason,
} from '../../../domain/admin/technologies/errors/AdminExperienceTechnologyError'
import { BackofficeHttpClient, violationsMessage } from '../shared/BackofficeHttpClient'

interface BackofficeExperienceTechnologyApiResponse {
  id: number
  name: string
  years: number
  iconKey?: string | null
  relatedTechnologyName?: string | null
}

const BASE_PATH = '/api/backoffice/experience/technologies'

/**
 * Implémentation HTTP de AdminExperienceTechnologyRepository. Contrairement à
 * HttpExperienceTechnologyRepository (endpoint public), toutes les méthodes
 * exigent le cookie httpOnly BEARER et les mutations le header CSRF
 * (cf. BackofficeHttpClient).
 */
export class HttpAdminExperienceTechnologyRepository implements AdminExperienceTechnologyRepository {
  private readonly client: BackofficeHttpClient

  constructor(apiBaseUrl: string) {
    this.client = new BackofficeHttpClient(apiBaseUrl)
  }

  async list(): Promise<readonly AdminExperienceTechnology[]> {
    const response = await this.client.get(BASE_PATH)

    if (!response.ok) {
      throw await this.toError(response)
    }

    const technologies = (await response.json()) as BackofficeExperienceTechnologyApiResponse[]

    return technologies.map(this.toEntity)
  }

  async create(input: AdminExperienceTechnologyInput): Promise<AdminExperienceTechnology> {
    const response = await this.mutate('POST', BASE_PATH, input)

    return this.toEntity((await response.json()) as BackofficeExperienceTechnologyApiResponse)
  }

  async update(id: number, input: AdminExperienceTechnologyInput): Promise<AdminExperienceTechnology> {
    const response = await this.mutate('PUT', `${BASE_PATH}/${id}`, input)

    return this.toEntity((await response.json()) as BackofficeExperienceTechnologyApiResponse)
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

  private toEntity(technology: BackofficeExperienceTechnologyApiResponse): AdminExperienceTechnology {
    return {
      id: technology.id,
      name: technology.name,
      years: technology.years,
      iconKey: technology.iconKey ?? null,
      relatedTechnologyName: technology.relatedTechnologyName ?? null,
    }
  }

  private async toError(response: Response): Promise<AdminExperienceTechnologyError> {
    const body = await this.client.parseProblem(response)

    if (409 === response.status) {
      return new AdminExperienceTechnologyError('duplicate', body.detail ?? 'Duplicate technology name')
    }
    if (404 === response.status) {
      return new AdminExperienceTechnologyError('not-found', 'Technology not found')
    }
    if (422 === response.status) {
      const reason: AdminExperienceTechnologyErrorReason = 'validation'
      return new AdminExperienceTechnologyError(reason, violationsMessage(body))
    }

    return new AdminExperienceTechnologyError('unknown', `Request failed with status ${response.status}`)
  }
}
