import type { AdminAboutSiteCard } from '../../../domain/admin/about/entities/AdminAboutSiteCard'
import type {
  AdminAboutSiteCardInput,
  AdminAboutSiteCardRepository,
} from '../../../domain/admin/about/repositories/AdminAboutSiteCardRepository'
import { AdminAboutError, type AdminAboutErrorReason } from '../../../domain/admin/about/errors/AdminAboutError'
import { AdminOrderError } from '../../../domain/admin/shared/errors/AdminOrderError'
import type { Locale } from '../../../domain/portfolio/entities/Locale'
import { BackofficeHttpClient, violationsMessage } from '../shared/BackofficeHttpClient'
import { STALE_ORDER_PROBLEM_TYPES, hasProblemType } from '../shared/orderProblems'

interface BackofficeAboutSiteCardApiResponse {
  id: string
  locale: string
  translationGroup: string
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

  /** Sans `?locale=` (spec 0004, D8) : le tableau du backoffice montre toutes les langues. */
  async list(): Promise<readonly AdminAboutSiteCard[]> {
    const response = await this.client.get(BASE_PATH)

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

  async update(id: string, input: AdminAboutSiteCardInput): Promise<AdminAboutSiteCard> {
    const response = await this.mutate('PUT', `${BASE_PATH}/${id}`, input)

    return this.toEntity((await response.json()) as BackofficeAboutSiteCardApiResponse)
  }

  async remove(id: string): Promise<void> {
    await this.mutate('DELETE', `${BASE_PATH}/${id}`)
  }

  async reorder(keys: readonly string[]): Promise<void> {
    const response = await this.client.mutate('PUT', `${BASE_PATH}/order`, { groups: keys })

    if (!response.ok) {
      throw await this.toOrderError(response)
    }
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
      translationGroup: card.translationGroup,
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
    if (409 === response.status) {
      return new AdminAboutError('translation-already-exists', violationsMessage(body, 'Translation already exists'))
    }
    if (422 === response.status) {
      const reason: AdminAboutErrorReason = hasProblemType(body, 'unknown-translation-group')
        ? 'unknown-translation-group'
        : 'validation'

      return new AdminAboutError(reason, violationsMessage(body))
    }

    return new AdminAboutError('unknown', `Request failed with status ${response.status}`)
  }

  /**
   * L'endpoint d'ordre a son propre type d'erreur : `useOrderDraft` décide sur
   * `stale-order` de recharger la liste, ce qu'aucun autre motif ne déclenche.
   */
  private async toOrderError(response: Response): Promise<AdminOrderError> {
    const body = await this.client.parseProblem(response)

    const isStale =
      422 === response.status && STALE_ORDER_PROBLEM_TYPES.some((problemType) => hasProblemType(body, problemType))

    return isStale
      ? new AdminOrderError('stale-order', violationsMessage(body, 'Order is stale'))
      : new AdminOrderError('unknown', `Reordering failed with status ${response.status}`)
  }
}
