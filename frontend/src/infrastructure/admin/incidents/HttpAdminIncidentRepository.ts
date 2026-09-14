import type { AdminIncident } from '../../../domain/admin/incidents/entities/AdminIncident'
import type {
  AdminIncidentInput,
  AdminIncidentRepository,
} from '../../../domain/admin/incidents/repositories/AdminIncidentRepository'
import {
  AdminIncidentError,
  type AdminIncidentErrorReason,
} from '../../../domain/admin/incidents/errors/AdminIncidentError'
import { AdminOrderError } from '../../../domain/admin/shared/errors/AdminOrderError'
import { BackofficeHttpClient, violationsMessage, type ApiProblemBody } from '../shared/BackofficeHttpClient'

interface BackofficeIncidentApiResponse {
  id: string
  locale: string
  translationGroup: string
  title: string
  version: string
  occurredAt: string
  impact: string
  rootCause: string
  resolution: string
  invariant: string
  position: number
}

const BASE_PATH = '/api/backoffice/incidents'

/**
 * Les deux `type` de problem+json que le backend renvoie en 422 quand
 * l'ensemble de clés envoyé ne correspond plus au périmètre (spec 0004, D4) :
 * une entrée a été créée ou supprimée entre le chargement de la page et
 * l'enregistrement. Ce n'est pas une erreur de saisie, c'est un conflit de
 * concurrence — d'où `stale-order`, qui déclenche un rechargement.
 */
const STALE_ORDER_PROBLEM_TYPES = ['unknown-order-entry', 'incomplete-order'] as const

/**
 * Implémentation HTTP de AdminIncidentRepository. Toutes les méthodes exigent
 * le cookie httpOnly BEARER, et les mutations le header CSRF
 * (cf. BackofficeHttpClient).
 */
export class HttpAdminIncidentRepository implements AdminIncidentRepository {
  private readonly client: BackofficeHttpClient

  constructor(apiBaseUrl: string) {
    this.client = new BackofficeHttpClient(apiBaseUrl)
  }

  async list(): Promise<readonly AdminIncident[]> {
    const response = await this.client.get(BASE_PATH)

    if (!response.ok) {
      throw await this.toError(response)
    }

    const incidents = (await response.json()) as BackofficeIncidentApiResponse[]

    return incidents.map(this.toEntity)
  }

  async create(input: AdminIncidentInput): Promise<AdminIncident> {
    const response = await this.mutate('POST', BASE_PATH, input)

    return this.toEntity((await response.json()) as BackofficeIncidentApiResponse)
  }

  async update(id: string, input: AdminIncidentInput): Promise<AdminIncident> {
    const response = await this.mutate('PUT', `${BASE_PATH}/${id}`, input)

    return this.toEntity((await response.json()) as BackofficeIncidentApiResponse)
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

  private toEntity(incident: BackofficeIncidentApiResponse): AdminIncident {
    return {
      id: incident.id,
      locale: incident.locale,
      translationGroup: incident.translationGroup,
      title: incident.title,
      version: incident.version,
      occurredAt: incident.occurredAt,
      impact: incident.impact,
      rootCause: incident.rootCause,
      resolution: incident.resolution,
      invariant: incident.invariant,
      position: incident.position,
    }
  }

  private async toError(response: Response): Promise<AdminIncidentError> {
    const body = await this.client.parseProblem(response)

    if (404 === response.status) {
      return new AdminIncidentError('not-found', 'Incident not found')
    }

    if (409 === response.status) {
      return new AdminIncidentError('translation-already-exists', violationsMessage(body, 'Translation already exists'))
    }

    if (422 === response.status) {
      const reason: AdminIncidentErrorReason = hasProblemType(body, 'unknown-translation-group')
        ? 'unknown-translation-group'
        : 'validation'

      return new AdminIncidentError(reason, violationsMessage(body))
    }

    return new AdminIncidentError('unknown', violationsMessage(body, `Request failed with status ${response.status}`))
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
