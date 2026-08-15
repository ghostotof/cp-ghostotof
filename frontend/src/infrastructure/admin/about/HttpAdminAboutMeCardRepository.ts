import type { AdminAboutMeCard, AdminAboutMeCardCategory } from '../../../domain/admin/about/entities/AdminAboutMeCard'
import type {
  AdminAboutMeCardInput,
  AdminAboutMeCardRepository,
} from '../../../domain/admin/about/repositories/AdminAboutMeCardRepository'
import { AdminAboutError, type AdminAboutErrorReason } from '../../../domain/admin/about/errors/AdminAboutError'
import type { Locale } from '../../../domain/portfolio/entities/Locale'
import { BackofficeHttpClient, violationsMessage } from '../shared/BackofficeHttpClient'

interface BackofficeAboutMeCardApiResponse {
  id: number
  locale: string
  category: string
  title: string
  description: string
  iconKey: string | null
  position: number
}

const BASE_PATH = '/api/backoffice/about/me-cards'

/**
 * Implémentation HTTP de AdminAboutMeCardRepository, même pattern que
 * HttpAdminExperienceTechnologyRepository (BackofficeHttpClient).
 */
export class HttpAdminAboutMeCardRepository implements AdminAboutMeCardRepository {
  private readonly client: BackofficeHttpClient

  constructor(apiBaseUrl: string) {
    this.client = new BackofficeHttpClient(apiBaseUrl)
  }

  async list(locale: Locale, category?: AdminAboutMeCardCategory): Promise<readonly AdminAboutMeCard[]> {
    const params = new URLSearchParams({ locale })
    if (category) {
      params.set('category', category)
    }
    const response = await this.client.get(`${BASE_PATH}?${params}`)

    if (!response.ok) {
      throw await this.toError(response)
    }

    const cards = (await response.json()) as BackofficeAboutMeCardApiResponse[]

    return cards.map(this.toEntity)
  }

  async create(input: AdminAboutMeCardInput): Promise<AdminAboutMeCard> {
    const response = await this.mutate('POST', BASE_PATH, input)

    return this.toEntity((await response.json()) as BackofficeAboutMeCardApiResponse)
  }

  async update(id: number, input: AdminAboutMeCardInput): Promise<AdminAboutMeCard> {
    const response = await this.mutate('PUT', `${BASE_PATH}/${id}`, input)

    return this.toEntity((await response.json()) as BackofficeAboutMeCardApiResponse)
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

  private toEntity(card: BackofficeAboutMeCardApiResponse): AdminAboutMeCard {
    return {
      id: card.id,
      locale: card.locale as Locale,
      category: card.category as AdminAboutMeCardCategory,
      title: card.title,
      description: card.description,
      iconKey: card.iconKey ?? null,
      position: card.position,
    }
  }

  private async toError(response: Response): Promise<AdminAboutError> {
    const body = await this.client.parseProblem(response)

    if (404 === response.status) {
      return new AdminAboutError('not-found', 'About me card not found')
    }
    if (422 === response.status) {
      const reason: AdminAboutErrorReason = 'validation'
      return new AdminAboutError(reason, violationsMessage(body))
    }

    return new AdminAboutError('unknown', `Request failed with status ${response.status}`)
  }
}
