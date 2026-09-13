import type { AdminAnonymousCvSection } from '../../../domain/admin/anonymousCv/entities/AdminAnonymousCvSection'
import type {
  AdminAnonymousCvSectionInput,
  AdminAnonymousCvSectionRepository,
} from '../../../domain/admin/anonymousCv/repositories/AdminAnonymousCvSectionRepository'
import {
  AdminAnonymousCvSectionError,
  type AdminAnonymousCvSectionErrorReason,
} from '../../../domain/admin/anonymousCv/errors/AdminAnonymousCvSectionError'
import { BackofficeHttpClient, violationsMessage } from '../shared/BackofficeHttpClient'

interface BackofficeAnonymousCvSectionApiResponse {
  id: number
  locale: string
  title: string
  skills: string
  yearsOfExperience: number
  achievements: string
  position: number
}

const BASE_PATH = '/api/backoffice/anonymous-cv'

/**
 * Implémentation HTTP d'AdminAnonymousCvSectionRepository. Toutes les méthodes
 * exigent le cookie httpOnly BEARER, et les mutations le header CSRF
 * (cf. BackofficeHttpClient).
 */
export class HttpAdminAnonymousCvSectionRepository implements AdminAnonymousCvSectionRepository {
  private readonly client: BackofficeHttpClient

  constructor(apiBaseUrl: string) {
    this.client = new BackofficeHttpClient(apiBaseUrl)
  }

  async list(): Promise<readonly AdminAnonymousCvSection[]> {
    const response = await this.client.get(BASE_PATH)

    if (!response.ok) {
      throw await this.toError(response)
    }

    const sections = (await response.json()) as BackofficeAnonymousCvSectionApiResponse[]

    return sections.map(this.toEntity)
  }

  async create(input: AdminAnonymousCvSectionInput): Promise<AdminAnonymousCvSection> {
    const response = await this.mutate('POST', BASE_PATH, input)

    return this.toEntity((await response.json()) as BackofficeAnonymousCvSectionApiResponse)
  }

  async update(id: number, input: AdminAnonymousCvSectionInput): Promise<AdminAnonymousCvSection> {
    const response = await this.mutate('PUT', `${BASE_PATH}/${id}`, input)

    return this.toEntity((await response.json()) as BackofficeAnonymousCvSectionApiResponse)
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

  private toEntity(section: BackofficeAnonymousCvSectionApiResponse): AdminAnonymousCvSection {
    return {
      id: section.id,
      locale: section.locale,
      title: section.title,
      skills: section.skills,
      yearsOfExperience: section.yearsOfExperience,
      achievements: section.achievements,
      position: section.position,
    }
  }

  private async toError(response: Response): Promise<AdminAnonymousCvSectionError> {
    const body = await this.client.parseProblem(response)

    if (404 === response.status) {
      return new AdminAnonymousCvSectionError('not-found', 'Anonymous CV section not found')
    }

    const reason: AdminAnonymousCvSectionErrorReason = 422 === response.status ? 'validation' : 'unknown'

    return new AdminAnonymousCvSectionError(reason, violationsMessage(body))
  }
}
