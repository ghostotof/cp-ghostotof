import type { AdminAboutSiteCard } from '../../../domain/admin/about/entities/AdminAboutSiteCard'
import type {
  AdminAboutSiteCardInput,
  AdminAboutSiteCardRepository,
} from '../../../domain/admin/about/repositories/AdminAboutSiteCardRepository'
import { AdminAboutError, type AdminAboutErrorReason } from '../../../domain/admin/about/errors/AdminAboutError'
import type { Locale } from '../../../domain/portfolio/entities/Locale'
import { BackofficeHttpClient, violationsMessage } from '../shared/BackofficeHttpClient'

interface BackofficeAboutSiteCardApiResponse {
  id: number
  locale: string
  title: string
  description: string
  iconKey: string | null
  position: number
}

const BASE_PATH = '/api/backoffice/about/site-cards'

/**
 * Implémentation HTTP de AdminAboutSiteCardRepository, même pattern que
 * HttpAdminExperienceTechnologyRepository (BackofficeHttpClient).
 */
export class HttpAdminAboutSiteCardRepository implements AdminAboutSiteCardRepository {
  private readonly client: BackofficeHttpClient

  constructor(apiBaseUrl: string) {
    this.client = new BackofficeHttpClient(apiBaseUrl)
  }

  async list(locale: Locale): Promise<readonly AdminAboutSiteCard[]> {
    const response = await this.client.get(`${BASE_PATH}?${new URLSearchParams({ locale })}`)

    if (!response.ok) {
      throw await this.toError(response)
    }

    const cards = (await response.json()) as BackofficeAboutSiteCardApiResponse[]

    return cards.map(this.toEntity)
  }

  async create(input: AdminAboutSiteCardInput): Promise<AdminAboutSiteCard> {
    const response = await this.mutate('POST', BASE_PATH, input)

    return this.toEntity((await response.json()) as BackofficeAboutSiteCardApiResponse)
  }

  async update(id: number, input: AdminAboutSiteCardInput): Promise<AdminAboutSiteCard> {
    const response = await this.mutate('PUT', `${BASE_PATH}/${id}`, input)

    return this.toEntity((await response.json()) as BackofficeAboutSiteCardApiResponse)
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

  private toEntity(card: BackofficeAboutSiteCardApiResponse): AdminAboutSiteCard {
    return {
      id: card.id,
      locale: card.locale as Locale,
      title: card.title,
      description: card.description,
      iconKey: card.iconKey ?? null,
      position: card.position,
    }
  }

  private async toError(response: Response): Promise<AdminAboutError> {
    const body = await this.client.parseProblem(response)

    if (404 === response.status) {
      return new AdminAboutError('not-found', 'About site card not found')
    }
    if (422 === response.status) {
      const reason: AdminAboutErrorReason = 'validation'
      return new AdminAboutError(reason, violationsMessage(body))
    }

    return new AdminAboutError('unknown', `Request failed with status ${response.status}`)
  }
}
