import type { AdminStat } from '../../../domain/admin/stats/entities/AdminStat'
import type { AdminStatInput, AdminStatsRepository } from '../../../domain/admin/stats/repositories/AdminStatsRepository'
import { AdminStatsError, type AdminStatsErrorReason } from '../../../domain/admin/stats/errors/AdminStatsError'
import type { Locale } from '../../../domain/portfolio/entities/Locale'
import { BackofficeHttpClient, violationsMessage } from '../shared/BackofficeHttpClient'

interface BackofficeStatApiResponse {
  id: number
  locale: string
  value: string
  label: string
  iconKey: string
  position: number
}

const BASE_PATH = '/api/backoffice/stats'

/**
 * Implémentation HTTP de AdminStatsRepository, sur BackofficeHttpClient (même
 * pattern que HttpAdminExperienceTechnologyRepository).
 */
export class HttpAdminStatsRepository implements AdminStatsRepository {
  private readonly client: BackofficeHttpClient

  constructor(apiBaseUrl: string) {
    this.client = new BackofficeHttpClient(apiBaseUrl)
  }

  async list(locale: Locale): Promise<readonly AdminStat[]> {
    const response = await this.client.get(`${BASE_PATH}?${new URLSearchParams({ locale })}`)

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
    const response = await this.client.mutate(method, path, body)

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
    const body = await this.client.parseProblem(response)

    if (404 === response.status) {
      return new AdminStatsError('not-found', 'Stat not found')
    }
    if (422 === response.status) {
      const reason: AdminStatsErrorReason = 'validation'
      return new AdminStatsError(reason, violationsMessage(body))
    }

    return new AdminStatsError('unknown', `Request failed with status ${response.status}`)
  }
}
