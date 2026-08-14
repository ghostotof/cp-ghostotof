import type { AdminExperienceTechnology } from '../../../domain/admin/technologies/entities/AdminExperienceTechnology'
import type {
  AdminExperienceTechnologyInput,
  AdminExperienceTechnologyRepository,
} from '../../../domain/admin/technologies/repositories/AdminExperienceTechnologyRepository'
import {
  AdminExperienceTechnologyError,
  type AdminExperienceTechnologyErrorReason,
} from '../../../domain/admin/technologies/errors/AdminExperienceTechnologyError'
import { readCsrfToken } from '../../auth/csrfCookie'

interface BackofficeExperienceTechnologyApiResponse {
  id: number
  name: string
  years: number
  iconKey?: string | null
  relatedTechnologyName?: string | null
}

/** Forme RFC7807-ish renvoyée par API Platform sur les réponses d'erreur (cf. api_platform.yaml `error_formats`). */
interface ApiProblemBody {
  detail?: string
  violations?: { propertyPath: string; message: string }[]
}

const BASE_PATH = '/api/backoffice/experience/technologies'

/**
 * Implémentation HTTP de AdminExperienceTechnologyRepository. Contrairement à
 * HttpExperienceTechnologyRepository (endpoint public), toutes les méthodes
 * exigent le cookie httpOnly BEARER (`credentials: 'include'`) et les
 * mutations le header CSRF X-XSRF-TOKEN (double-submit cookie, cf.
 * HttpAuthRepository.logout() / backend CsrfCookieRequestSubscriber).
 */
export class HttpAdminExperienceTechnologyRepository implements AdminExperienceTechnologyRepository {
  private readonly apiBaseUrl: string

  constructor(apiBaseUrl: string) {
    this.apiBaseUrl = apiBaseUrl
  }

  async list(): Promise<readonly AdminExperienceTechnology[]> {
    const response = await fetch(`${this.apiBaseUrl}${BASE_PATH}`, {
      method: 'GET',
      credentials: 'include',
    })

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
    const csrfToken = readCsrfToken()

    const response = await fetch(`${this.apiBaseUrl}${path}`, {
      method,
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        ...(csrfToken ? { 'X-XSRF-TOKEN': csrfToken } : {}),
      },
      body: body ? JSON.stringify(body) : undefined,
    })

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
    const body = (await response.json().catch(() => ({}))) as ApiProblemBody

    if (409 === response.status) {
      return new AdminExperienceTechnologyError('duplicate', body.detail ?? 'Duplicate technology name')
    }
    if (404 === response.status) {
      return new AdminExperienceTechnologyError('not-found', 'Technology not found')
    }
    if (422 === response.status) {
      const reason: AdminExperienceTechnologyErrorReason = 'validation'
      const message = body.violations?.map((violation) => violation.message).join(' ') ?? body.detail ?? 'Validation failed'
      return new AdminExperienceTechnologyError(reason, message)
    }

    return new AdminExperienceTechnologyError('unknown', `Request failed with status ${response.status}`)
  }
}
