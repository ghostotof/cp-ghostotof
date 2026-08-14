import type { AdminStat } from '../../../domain/admin/stats/entities/AdminStat'
import type { AdminStatInput, AdminStatsRepository } from '../../../domain/admin/stats/repositories/AdminStatsRepository'
import { AdminStatsError, type AdminStatsErrorReason } from '../../../domain/admin/stats/errors/AdminStatsError'
import type { Locale } from '../../../domain/portfolio/entities/Locale'
import { readCsrfToken } from '../../auth/csrfCookie'

interface BackofficeStatApiResponse {
  id: number
  locale: string
  value: string
  label: string
  iconKey: string
  position: number
}

/** Forme RFC7807-ish renvoyée par API Platform sur les réponses d'erreur (cf. api_platform.yaml `error_formats`). */
interface ApiProblemBody {
  detail?: string
  violations?: { propertyPath: string; message: string }[]
}

const BASE_PATH = '/api/backoffice/stats'

/**
 * Implémentation HTTP de AdminStatsRepository. Toutes les méthodes exigent le
 * cookie httpOnly BEARER (`credentials: 'include'`) et les mutations le
 * header CSRF X-XSRF-TOKEN (double-submit cookie), même pattern que
 * HttpAdminExperienceTechnologyRepository.
 */
export class HttpAdminStatsRepository implements AdminStatsRepository {
  private readonly apiBaseUrl: string

  constructor(apiBaseUrl: string) {
    this.apiBaseUrl = apiBaseUrl
  }

  async list(locale: Locale): Promise<readonly AdminStat[]> {
    const response = await fetch(`${this.apiBaseUrl}${BASE_PATH}?locale=${locale}`, {
      method: 'GET',
      credentials: 'include',
    })

    if (!response.ok) {
      throw await this.toError(response)
    }

    const stats = (await response.json()) as BackofficeStatApiResponse[]

    return stats.map(this.toEntity)
  }

  async create(input: AdminStatInput): Promise<AdminStat> {
    const response = await this.mutate('POST', BASE_PATH, input)

    return this.toEntity((await response.json()) as BackofficeStatApiResponse)
  }

  async update(id: number, input: AdminStatInput): Promise<AdminStat> {
    const response = await this.mutate('PUT', `${BASE_PATH}/${id}`, input)

    return this.toEntity((await response.json()) as BackofficeStatApiResponse)
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

  private toEntity(stat: BackofficeStatApiResponse): AdminStat {
    return {
      id: stat.id,
      locale: stat.locale as Locale,
      value: stat.value,
      label: stat.label,
      iconKey: stat.iconKey,
      position: stat.position,
    }
  }

  private async toError(response: Response): Promise<AdminStatsError> {
    const body = (await response.json().catch(() => ({}))) as ApiProblemBody

    if (404 === response.status) {
      return new AdminStatsError('not-found', 'Stat not found')
    }
    if (422 === response.status) {
      const reason: AdminStatsErrorReason = 'validation'
      const message = body.violations?.map((violation) => violation.message).join(' ') ?? body.detail ?? 'Validation failed'
      return new AdminStatsError(reason, message)
    }

    return new AdminStatsError('unknown', `Request failed with status ${response.status}`)
  }
}
