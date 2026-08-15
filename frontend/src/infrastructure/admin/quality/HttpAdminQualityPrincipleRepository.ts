import type { AdminQualityPrinciple } from '../../../domain/admin/quality/entities/AdminQualityPrinciple'
import type {
  AdminQualityPrincipleInput,
  AdminQualityPrincipleRepository,
} from '../../../domain/admin/quality/repositories/AdminQualityPrincipleRepository'
import { AdminQualityError, type AdminQualityErrorReason } from '../../../domain/admin/quality/errors/AdminQualityError'
import type { Locale } from '../../../domain/portfolio/entities/Locale'
import { BackofficeHttpClient, violationsMessage } from '../shared/BackofficeHttpClient'

interface BackofficeQualityPrincipleApiResponse {
  id: number
  locale: string
  title: string
  description: string
  iconKey: string
  position: number
}

const BASE_PATH = '/api/backoffice/quality/principles'

/**
 * Implémentation HTTP de AdminQualityPrincipleRepository, même pattern que
 * HttpAdminExperienceTechnologyRepository (BackofficeHttpClient).
 */
export class HttpAdminQualityPrincipleRepository implements AdminQualityPrincipleRepository {
  private readonly client: BackofficeHttpClient

  constructor(apiBaseUrl: string) {
    this.client = new BackofficeHttpClient(apiBaseUrl)
  }

  async list(locale: Locale): Promise<readonly AdminQualityPrinciple[]> {
    const response = await this.client.get(`${BASE_PATH}?${new URLSearchParams({ locale })}`)

    if (!response.ok) {
      throw await this.toError(response)
    }

    const principles = (await response.json()) as BackofficeQualityPrincipleApiResponse[]

    return principles.map(this.toEntity)
  }

  async create(input: AdminQualityPrincipleInput): Promise<AdminQualityPrinciple> {
    const response = await this.mutate('POST', BASE_PATH, input)

    return this.toEntity((await response.json()) as BackofficeQualityPrincipleApiResponse)
  }

  async update(id: number, input: AdminQualityPrincipleInput): Promise<AdminQualityPrinciple> {
    const response = await this.mutate('PUT', `${BASE_PATH}/${id}`, input)

    return this.toEntity((await response.json()) as BackofficeQualityPrincipleApiResponse)
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

  private toEntity(principle: BackofficeQualityPrincipleApiResponse): AdminQualityPrinciple {
    return {
      id: principle.id,
      locale: principle.locale as Locale,
      title: principle.title,
      description: principle.description,
      iconKey: principle.iconKey,
      position: principle.position,
    }
  }

  private async toError(response: Response): Promise<AdminQualityError> {
    const body = await this.client.parseProblem(response)

    if (404 === response.status) {
      return new AdminQualityError('not-found', 'Quality principle not found')
    }
    if (422 === response.status) {
      const reason: AdminQualityErrorReason = 'validation'
      return new AdminQualityError(reason, violationsMessage(body))
    }

    return new AdminQualityError('unknown', `Request failed with status ${response.status}`)
  }
}
