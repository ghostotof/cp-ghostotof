import type { AdminAboutMeCard, AdminAboutMeCardCategory } from '../../../domain/admin/about/entities/AdminAboutMeCard'
import type {
  AdminAboutMeCardInput,
  AdminAboutMeCardRepository,
} from '../../../domain/admin/about/repositories/AdminAboutMeCardRepository'
import { AdminAboutError, type AdminAboutErrorReason } from '../../../domain/admin/about/errors/AdminAboutError'
import type { Locale } from '../../../domain/portfolio/entities/Locale'
import { readCsrfToken } from '../../auth/csrfCookie'

interface BackofficeAboutMeCardApiResponse {
  id: number
  locale: string
  category: string
  title: string
  description: string
  iconKey: string | null
  position: number
}

/** Forme RFC7807-ish renvoyée par API Platform sur les réponses d'erreur (cf. api_platform.yaml `error_formats`). */
interface ApiProblemBody {
  detail?: string
  violations?: { propertyPath: string; message: string }[]
}

const BASE_PATH = '/api/backoffice/about/me-cards'

/**
 * Implémentation HTTP de AdminAboutMeCardRepository, même pattern que
 * HttpAdminExperienceTechnologyRepository (credentials + header CSRF sur les
 * mutations).
 */
export class HttpAdminAboutMeCardRepository implements AdminAboutMeCardRepository {
  private readonly apiBaseUrl: string

  constructor(apiBaseUrl: string) {
    this.apiBaseUrl = apiBaseUrl
  }

  async list(locale: Locale, category?: AdminAboutMeCardCategory): Promise<readonly AdminAboutMeCard[]> {
    const query = category ? `?locale=${locale}&category=${category}` : `?locale=${locale}`
    const response = await fetch(`${this.apiBaseUrl}${BASE_PATH}${query}`, {
      method: 'GET',
      credentials: 'include',
    })

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
    const body = (await response.json().catch(() => ({}))) as ApiProblemBody

    if (404 === response.status) {
      return new AdminAboutError('not-found', 'About me card not found')
    }
    if (422 === response.status) {
      const reason: AdminAboutErrorReason = 'validation'
      const message = body.violations?.map((violation) => violation.message).join(' ') ?? body.detail ?? 'Validation failed'
      return new AdminAboutError(reason, message)
    }

    return new AdminAboutError('unknown', `Request failed with status ${response.status}`)
  }
}
