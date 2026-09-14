import type { AdminCaseStudy } from '../../../domain/admin/caseStudies/entities/AdminCaseStudy'
import type {
  AdminCaseStudyInput,
  AdminCaseStudyRepository,
} from '../../../domain/admin/caseStudies/repositories/AdminCaseStudyRepository'
import {
  AdminCaseStudyError,
  type AdminCaseStudyErrorReason,
} from '../../../domain/admin/caseStudies/errors/AdminCaseStudyError'
import { AdminOrderError } from '../../../domain/admin/shared/errors/AdminOrderError'
import { BackofficeHttpClient, violationsMessage } from '../shared/BackofficeHttpClient'
import { STALE_ORDER_PROBLEM_TYPES, hasProblemType } from '../shared/orderProblems'

interface BackofficeCaseStudyApiResponse {
  id: string
  locale: string
  translationGroup: string
  title: string
  problem: string
  solution: string
  tradeoffs: string
  measuredResult: string
  position: number
}

const BASE_PATH = '/api/backoffice/case-studies'

/**
 * Implémentation HTTP de AdminCaseStudyRepository. Toutes les
 * méthodes exigent le cookie httpOnly BEARER, et les mutations le header CSRF
 * (cf. BackofficeHttpClient).
 */
export class HttpAdminCaseStudyRepository implements AdminCaseStudyRepository {
  private readonly client: BackofficeHttpClient

  constructor(apiBaseUrl: string) {
    this.client = new BackofficeHttpClient(apiBaseUrl)
  }

  async list(): Promise<readonly AdminCaseStudy[]> {
    const response = await this.client.get(BASE_PATH)

    if (!response.ok) {
      throw await this.toError(response)
    }

    const caseStudies = (await response.json()) as BackofficeCaseStudyApiResponse[]

    return caseStudies.map(this.toEntity)
  }

  async create(input: AdminCaseStudyInput): Promise<AdminCaseStudy> {
    const response = await this.mutate('POST', BASE_PATH, input)

    return this.toEntity((await response.json()) as BackofficeCaseStudyApiResponse)
  }

  async update(id: string, input: AdminCaseStudyInput): Promise<AdminCaseStudy> {
    const response = await this.mutate('PUT', `${BASE_PATH}/${id}`, input)

    return this.toEntity((await response.json()) as BackofficeCaseStudyApiResponse)
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

  private toEntity(caseStudy: BackofficeCaseStudyApiResponse): AdminCaseStudy {
    return {
      id: caseStudy.id,
      locale: caseStudy.locale,
      translationGroup: caseStudy.translationGroup,
      title: caseStudy.title,
      problem: caseStudy.problem,
      solution: caseStudy.solution,
      tradeoffs: caseStudy.tradeoffs,
      measuredResult: caseStudy.measuredResult,
      position: caseStudy.position,
    }
  }

  private async toError(response: Response): Promise<AdminCaseStudyError> {
    const body = await this.client.parseProblem(response)

    if (404 === response.status) {
      return new AdminCaseStudyError('not-found', 'Case study not found')
    }

    if (409 === response.status) {
      return new AdminCaseStudyError(
        'translation-already-exists',
        violationsMessage(body, 'Translation already exists'),
      )
    }

    if (422 === response.status) {
      const reason: AdminCaseStudyErrorReason = hasProblemType(body, 'unknown-translation-group')
        ? 'unknown-translation-group'
        : 'validation'

      return new AdminCaseStudyError(reason, violationsMessage(body))
    }

    return new AdminCaseStudyError(
      'unknown',
      violationsMessage(body, `Request failed with status ${response.status}`),
    )
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
