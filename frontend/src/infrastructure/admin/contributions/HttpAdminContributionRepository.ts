import type { AdminContribution } from '../../../domain/admin/contributions/entities/AdminContribution'
import type {
  AdminContributionInput,
  AdminContributionRepository,
} from '../../../domain/admin/contributions/repositories/AdminContributionRepository'
import {
  AdminContributionError,
  type AdminContributionErrorReason,
} from '../../../domain/admin/contributions/errors/AdminContributionError'
import { BackofficeHttpClient, violationsMessage } from '../shared/BackofficeHttpClient'

interface BackofficeContributionApiResponse {
  id: number
  locale: string
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
 * Implémentation HTTP de AdminContributionRepository. Contrairement à
 * HttpContributionRepository (endpoint public), toutes les méthodes exigent le
 * cookie httpOnly BEARER et les mutations le header CSRF
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

  async update(id: number, input: AdminContributionInput): Promise<AdminContribution> {
    const response = await this.mutate('PUT', `${BASE_PATH}/${id}`, input)

    return this.toEntity((await response.json()) as BackofficeContributionApiResponse)
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

  private toEntity(contribution: BackofficeContributionApiResponse): AdminContribution {
    return {
      id: contribution.id,
      locale: contribution.locale,
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

    const reason: AdminContributionErrorReason = 422 === response.status ? 'validation' : 'unknown'

    return new AdminContributionError(reason, violationsMessage(body))
  }
}
