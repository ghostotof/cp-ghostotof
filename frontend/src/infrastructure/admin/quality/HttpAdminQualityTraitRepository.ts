import type { AdminQualityTrait } from '../../../domain/admin/quality/entities/AdminQualityTrait'
import type {
  AdminQualityTraitInput,
  AdminQualityTraitRepository,
} from '../../../domain/admin/quality/repositories/AdminQualityTraitRepository'
import { AdminQualityError, type AdminQualityErrorReason } from '../../../domain/admin/quality/errors/AdminQualityError'
import { AdminOrderError } from '../../../domain/admin/shared/errors/AdminOrderError'
import type { Locale } from '../../../domain/portfolio/entities/Locale'
import { BackofficeHttpClient, violationsMessage } from '../shared/BackofficeHttpClient'
import { STALE_ORDER_PROBLEM_TYPES, hasProblemType } from '../shared/orderProblems'

interface BackofficeQualityTraitApiResponse {
  id: string
  locale: string
  translationGroup: string
  label: string
  position: number
}

const BASE_PATH = '/api/backoffice/quality/traits'

/**
 * Implémentation HTTP de AdminQualityTraitRepository, même pattern que
 * HttpAdminQualityPrincipleRepository (BackofficeHttpClient).
 */
export class HttpAdminQualityTraitRepository implements AdminQualityTraitRepository {
  private readonly client: BackofficeHttpClient

  constructor(apiBaseUrl: string) {
    this.client = new BackofficeHttpClient(apiBaseUrl)
  }

  /** Sans `?locale=` (spec 0004, D8) : le tableau du backoffice montre toutes les langues. */
  async list(): Promise<readonly AdminQualityTrait[]> {
    const response = await this.client.get(BASE_PATH)

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

  async update(id: string, input: AdminQualityTraitInput): Promise<AdminQualityTrait> {
    const response = await this.mutate('PUT', `${BASE_PATH}/${id}`, input)

    return this.toEntity((await response.json()) as BackofficeQualityTraitApiResponse)
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

  private toEntity(trait: BackofficeQualityTraitApiResponse): AdminQualityTrait {
    return {
      id: trait.id,
      locale: trait.locale as Locale,
      translationGroup: trait.translationGroup,
      label: trait.label,
      position: trait.position,
    }
  }

  private async toError(response: Response): Promise<AdminQualityError> {
    const body = await this.client.parseProblem(response)

    if (404 === response.status) {
      return new AdminQualityError('not-found', 'Quality trait not found')
    }

    if (409 === response.status) {
      return new AdminQualityError('translation-already-exists', violationsMessage(body, 'Translation already exists'))
    }

    if (422 === response.status) {
      const reason: AdminQualityErrorReason = hasProblemType(body, 'unknown-translation-group')
        ? 'unknown-translation-group'
        : 'validation'

      return new AdminQualityError(reason, violationsMessage(body))
    }

    return new AdminQualityError('unknown', `Request failed with status ${response.status}`)
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
