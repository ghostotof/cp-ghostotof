import type { AdminContribution } from '../../../domain/admin/contributions/entities/AdminContribution'
import type {
  AdminContributionInput,
  AdminContributionRepository,
} from '../../../domain/admin/contributions/repositories/AdminContributionRepository'
import {
  AdminContributionError,
  type AdminContributionErrorReason,
} from '../../../domain/admin/contributions/errors/AdminContributionError'
import { AdminOrderError } from '../../../domain/admin/shared/errors/AdminOrderError'
import { BackofficeHttpClient, violationsMessage } from '../shared/BackofficeHttpClient'
import { STALE_ORDER_PROBLEM_TYPES, hasProblemType } from '../shared/orderProblems'

interface BackofficeContributionApiResponse {
  id: string
  locale: string
  translationGroup: string
  title: string
  project: string
  reference: string
  url: string
  summary: string
  body: string
  position: number
}

const BASE_PATH = '/api/backoffice/contributions'

/**
 * Implémentation HTTP de AdminContributionRepository. Toutes les méthodes
 * exigent le cookie httpOnly BEARER, et les mutations le header CSRF
 * (cf. BackofficeHttpClient).
 */
export class HttpAdminContributionRepository implements AdminContributionRepository {
  private readonly client: BackofficeHttpClient

  constructor(apiBaseUrl: string) {
    this.client = new BackofficeHttpClient(apiBaseUrl)
  }

  async list(): Promise<readonly AdminContribution[]> {
    const response = await this.client.get(BASE_PATH)

    if (!response.ok) {
      throw await this.toError(response)
    }

    const contributions = (await response.json()) as BackofficeContributionApiResponse[]

    return contributions.map(this.toEntity)
  }

  async create(input: AdminContributionInput): Promise<AdminContribution> {
    const response = await this.mutate('POST', BASE_PATH, input)

    return this.toEntity((await response.json()) as BackofficeContributionApiResponse)
  }

  async update(id: string, input: AdminContributionInput): Promise<AdminContribution> {
    const response = await this.mutate('PUT', `${BASE_PATH}/${id}`, input)

    return this.toEntity((await response.json()) as BackofficeContributionApiResponse)
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

  private toEntity(contribution: BackofficeContributionApiResponse): AdminContribution {
    return {
      id: contribution.id,
      locale: contribution.locale,
      translationGroup: contribution.translationGroup,
      title: contribution.title,
      project: contribution.project,
      reference: contribution.reference,
      url: contribution.url,
      summary: contribution.summary,
      body: contribution.body,
      position: contribution.position,
    }
  }

  private async toError(response: Response): Promise<AdminContributionError> {
    const body = await this.client.parseProblem(response)

    if (404 === response.status) {
      return new AdminContributionError('not-found', 'Contribution not found')
    }

    if (409 === response.status) {
      return new AdminContributionError(
        'translation-already-exists',
        violationsMessage(body, 'Translation already exists'),
      )
    }

    if (422 === response.status) {
      const reason: AdminContributionErrorReason = hasProblemType(body, 'unknown-translation-group')
        ? 'unknown-translation-group'
        : 'validation'

      return new AdminContributionError(reason, violationsMessage(body))
    }

    return new AdminContributionError('unknown', violationsMessage(body, `Request failed with status ${response.status}`))
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
