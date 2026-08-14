import type { AdminQualityTrait } from '../../../domain/admin/quality/entities/AdminQualityTrait'
import type {
  AdminQualityTraitInput,
  AdminQualityTraitRepository,
} from '../../../domain/admin/quality/repositories/AdminQualityTraitRepository'
import { AdminQualityError, type AdminQualityErrorReason } from '../../../domain/admin/quality/errors/AdminQualityError'
import type { Locale } from '../../../domain/portfolio/entities/Locale'
import { readCsrfToken } from '../../auth/csrfCookie'

interface BackofficeQualityTraitApiResponse {
  id: number
  locale: string
  label: string
  position: number
}

/** Forme RFC7807-ish renvoyée par API Platform sur les réponses d'erreur (cf. api_platform.yaml `error_formats`). */
interface ApiProblemBody {
  detail?: string
  violations?: { propertyPath: string; message: string }[]
}

const BASE_PATH = '/api/backoffice/quality/traits'

/**
 * Implémentation HTTP de AdminQualityTraitRepository, même pattern que
 * HttpAdminExperienceTechnologyRepository (credentials + header CSRF sur les
 * mutations).
 */
export class HttpAdminQualityTraitRepository implements AdminQualityTraitRepository {
  private readonly apiBaseUrl: string

  constructor(apiBaseUrl: string) {
    this.apiBaseUrl = apiBaseUrl
  }

  async list(locale: Locale): Promise<readonly AdminQualityTrait[]> {
    const response = await fetch(`${this.apiBaseUrl}${BASE_PATH}?locale=${locale}`, {
      method: 'GET',
      credentials: 'include',
    })

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

  async update(id: number, input: AdminQualityTraitInput): Promise<AdminQualityTrait> {
    const response = await this.mutate('PUT', `${BASE_PATH}/${id}`, input)

    return this.toEntity((await response.json()) as BackofficeQualityTraitApiResponse)
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

  private toEntity(trait: BackofficeQualityTraitApiResponse): AdminQualityTrait {
    return {
      id: trait.id,
      locale: trait.locale as Locale,
      label: trait.label,
      position: trait.position,
    }
  }

  private async toError(response: Response): Promise<AdminQualityError> {
    const body = (await response.json().catch(() => ({}))) as ApiProblemBody

    if (404 === response.status) {
      return new AdminQualityError('not-found', 'Quality trait not found')
    }
    if (422 === response.status) {
      const reason: AdminQualityErrorReason = 'validation'
      const message = body.violations?.map((violation) => violation.message).join(' ') ?? body.detail ?? 'Validation failed'
      return new AdminQualityError(reason, message)
    }

    return new AdminQualityError('unknown', `Request failed with status ${response.status}`)
  }
}
