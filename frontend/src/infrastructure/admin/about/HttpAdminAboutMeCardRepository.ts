import type { AdminAboutMeCard, AdminAboutMeCardCategory } from '../../../domain/admin/about/entities/AdminAboutMeCard'
import type {
  AdminAboutMeCardInput,
  AdminAboutMeCardRepository,
} from '../../../domain/admin/about/repositories/AdminAboutMeCardRepository'
import { AdminAboutError, type AdminAboutErrorReason } from '../../../domain/admin/about/errors/AdminAboutError'
import { AdminOrderError } from '../../../domain/admin/shared/errors/AdminOrderError'
import type { Locale } from '../../../domain/portfolio/entities/Locale'
import { BackofficeHttpClient, violationsMessage } from '../shared/BackofficeHttpClient'
import { STALE_ORDER_PROBLEM_TYPES, hasProblemType } from '../shared/orderProblems'

interface BackofficeAboutMeCardApiResponse {
  id: string
  locale: string
  translationGroup: string
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

  /**
   * Sans `?locale=` ni `?category=` (spec 0004, D8) : la page rend les trois
   * tableaux à la fois, toutes langues confondues, et répartit localement.
   */
  async list(): Promise<readonly AdminAboutMeCard[]> {
    const response = await this.client.get(BASE_PATH)

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

  async update(id: string, input: AdminAboutMeCardInput): Promise<AdminAboutMeCard> {
    const response = await this.mutate('PUT', `${BASE_PATH}/${id}`, input)

    return this.toEntity((await response.json()) as BackofficeAboutMeCardApiResponse)
  }

  async remove(id: string): Promise<void> {
    await this.mutate('DELETE', `${BASE_PATH}/${id}`)
  }

  /** `{ category, groups }` : le périmètre d'ordre est la catégorie (cf. l'interface). */
  async reorder(keys: readonly string[], category: AdminAboutMeCardCategory): Promise<void> {
    const response = await this.client.mutate('PUT', `${BASE_PATH}/order`, { category, groups: keys })

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

  private toEntity(card: BackofficeAboutMeCardApiResponse): AdminAboutMeCard {
    return {
      id: card.id,
      locale: card.locale as Locale,
      translationGroup: card.translationGroup,
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
    if (409 === response.status) {
      return new AdminAboutError('translation-already-exists', violationsMessage(body, 'Translation already exists'))
    }
    if (422 === response.status) {
      // Couvre aussi le rattachement qui traverserait les catégories : côté
      // serveur, un groupe d'une autre catégorie est simplement inconnu du
      // périmètre.
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
