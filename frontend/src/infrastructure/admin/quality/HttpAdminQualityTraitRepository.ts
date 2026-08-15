import type { AdminQualityTrait } from '../../../domain/admin/quality/entities/AdminQualityTrait'
import type {
  AdminQualityTraitInput,
  AdminQualityTraitRepository,
} from '../../../domain/admin/quality/repositories/AdminQualityTraitRepository'
import { AdminQualityError, type AdminQualityErrorReason } from '../../../domain/admin/quality/errors/AdminQualityError'
import type { Locale } from '../../../domain/portfolio/entities/Locale'
import { BackofficeHttpClient, violationsMessage } from '../shared/BackofficeHttpClient'

interface BackofficeQualityTraitApiResponse {
  id: number
  locale: string
  label: string
  position: number
}

const BASE_PATH = '/api/backoffice/quality/traits'

/**
 * Implémentation HTTP de AdminQualityTraitRepository, même pattern que
 * HttpAdminExperienceTechnologyRepository (BackofficeHttpClient).
 */
export class HttpAdminQualityTraitRepository implements AdminQualityTraitRepository {
  private readonly client: BackofficeHttpClient

  constructor(apiBaseUrl: string) {
    this.client = new BackofficeHttpClient(apiBaseUrl)
  }

  async list(locale: Locale): Promise<readonly AdminQualityTrait[]> {
    const response = await this.client.get(`${BASE_PATH}?${new URLSearchParams({ locale })}`)

    if (!response.ok) {
      throw await this.toError(response)
    }

    const traits = (await response.json()) as BackofficeQualityTraitApiResponse[]

    return traits.map(this.toEntity)
  }

  async create(input: AdminQualityTraitInput): Promise<AdminQualityTrait> {
    const response = await this.mutate('POST', BASE_PATH, input)

    return this.toEntity((await response.json()) as BackofficeQualityTraitApiResponse)
  }

  async update(id: number, input: AdminQualityTraitInput): Promise<AdminQualityTrait> {
    const response = await this.mutate('PUT', `${BASE_PATH}/${id}`, input)

    return this.toEntity((await response.json()) as BackofficeQualityTraitApiResponse)
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

  private toEntity(trait: BackofficeQualityTraitApiResponse): AdminQualityTrait {
    return {
      id: trait.id,
      locale: trait.locale as Locale,
      label: trait.label,
      position: trait.position,
    }
  }

  private async toError(response: Response): Promise<AdminQualityError> {
    const body = await this.client.parseProblem(response)

    if (404 === response.status) {
      return new AdminQualityError('not-found', 'Quality trait not found')
    }
    if (422 === response.status) {
      const reason: AdminQualityErrorReason = 'validation'
      return new AdminQualityError(reason, violationsMessage(body))
    }

    return new AdminQualityError('unknown', `Request failed with status ${response.status}`)
  }
}
