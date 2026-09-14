import type { AdminAnonymousCvSection } from '../../../domain/admin/anonymousCv/entities/AdminAnonymousCvSection'
import type {
  AdminAnonymousCvSectionInput,
  AdminAnonymousCvSectionRepository,
} from '../../../domain/admin/anonymousCv/repositories/AdminAnonymousCvSectionRepository'
import {
  AdminAnonymousCvSectionError,
  type AdminAnonymousCvSectionErrorReason,
} from '../../../domain/admin/anonymousCv/errors/AdminAnonymousCvSectionError'
import { AdminOrderError } from '../../../domain/admin/shared/errors/AdminOrderError'
import { BackofficeHttpClient, violationsMessage, type ApiProblemBody } from '../shared/BackofficeHttpClient'

interface BackofficeAnonymousCvSectionApiResponse {
  id: string
  locale: string
  translationGroup: string
  title: string
  skills: string
  yearsOfExperience: number
  achievements: string
  position: number
}

const BASE_PATH = '/api/backoffice/anonymous-cv'

/**
 * Les deux `type` de problem+json que le backend renvoie en 422 quand
 * l'ensemble de clés envoyé ne correspond plus au périmètre (spec 0004, D4) :
 * une entrée a été créée ou supprimée entre le chargement de la page et
 * l'enregistrement. Ce n'est pas une erreur de saisie, c'est un conflit de
 * concurrence — d'où `stale-order`, qui déclenche un rechargement.
 */
const STALE_ORDER_PROBLEM_TYPES = ['unknown-order-entry', 'incomplete-order'] as const

/**
 * Implémentation HTTP de AdminAnonymousCvSectionRepository. Toutes les
 * méthodes exigent le cookie httpOnly BEARER, et les mutations le header CSRF
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

  async update(id: string, input: AdminAnonymousCvSectionInput): Promise<AdminAnonymousCvSection> {
    const response = await this.mutate('PUT', `${BASE_PATH}/${id}`, input)

    return this.toEntity((await response.json()) as BackofficeAnonymousCvSectionApiResponse)
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

  private toEntity(section: BackofficeAnonymousCvSectionApiResponse): AdminAnonymousCvSection {
    return {
      id: section.id,
      locale: section.locale,
      translationGroup: section.translationGroup,
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

    if (409 === response.status) {
      return new AdminAnonymousCvSectionError(
        'translation-already-exists',
        violationsMessage(body, 'Translation already exists'),
      )
    }

    if (422 === response.status) {
      const reason: AdminAnonymousCvSectionErrorReason = hasProblemType(body, 'unknown-translation-group')
        ? 'unknown-translation-group'
        : 'validation'

      return new AdminAnonymousCvSectionError(reason, violationsMessage(body))
    }

    return new AdminAnonymousCvSectionError(
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

/** Le `type` est un slug stable (`/errors/<slug>`), contrairement au `detail` localisé. */
function hasProblemType(body: ApiProblemBody, slug: string): boolean {
  return true === body.type?.endsWith(`/${slug}`)
}
