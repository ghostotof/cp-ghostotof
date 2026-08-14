import type { AdminAboutSettings } from '../../../domain/admin/about/entities/AdminAboutSettings'
import type {
  AdminAboutSettingsInput,
  AdminAboutSettingsRepository,
} from '../../../domain/admin/about/repositories/AdminAboutSettingsRepository'
import { AdminAboutError, type AdminAboutErrorReason } from '../../../domain/admin/about/errors/AdminAboutError'
import type { Locale } from '../../../domain/portfolio/entities/Locale'
import { readCsrfToken } from '../../auth/csrfCookie'

interface BackofficeAboutSettingsApiResponse {
  locale: string
  siteEyebrow: string
  meEyebrow: string
  technicalSubtitle: string
  personalSubtitle: string
  hobbiesSubtitle: string
}

/** Forme RFC7807-ish renvoyée par API Platform sur les réponses d'erreur (cf. api_platform.yaml `error_formats`). */
interface ApiProblemBody {
  detail?: string
  violations?: { propertyPath: string; message: string }[]
}

const BASE_PATH = '/api/backoffice/about/settings'

/**
 * Implémentation HTTP de AdminAboutSettingsRepository. La locale EST
 * l'identifiant de la ressource (pas de {id} numérique), même pattern CSRF
 * que HttpAdminExperienceTechnologyRepository pour la mutation.
 */
export class HttpAdminAboutSettingsRepository implements AdminAboutSettingsRepository {
  private readonly apiBaseUrl: string

  constructor(apiBaseUrl: string) {
    this.apiBaseUrl = apiBaseUrl
  }

  async get(locale: Locale): Promise<AdminAboutSettings> {
    const response = await fetch(`${this.apiBaseUrl}${BASE_PATH}/${locale}`, {
      method: 'GET',
      credentials: 'include',
    })

    if (!response.ok) {
      throw await this.toError(response)
    }

    return this.toEntity((await response.json()) as BackofficeAboutSettingsApiResponse)
  }

  async update(locale: Locale, input: AdminAboutSettingsInput): Promise<AdminAboutSettings> {
    const csrfToken = readCsrfToken()

    const response = await fetch(`${this.apiBaseUrl}${BASE_PATH}/${locale}`, {
      method: 'PUT',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        ...(csrfToken ? { 'X-XSRF-TOKEN': csrfToken } : {}),
      },
      body: JSON.stringify({ locale, ...input }),
    })

    if (!response.ok) {
      throw await this.toError(response)
    }

    return this.toEntity((await response.json()) as BackofficeAboutSettingsApiResponse)
  }

  private toEntity(settings: BackofficeAboutSettingsApiResponse): AdminAboutSettings {
    return {
      locale: settings.locale as Locale,
      siteEyebrow: settings.siteEyebrow,
      meEyebrow: settings.meEyebrow,
      technicalSubtitle: settings.technicalSubtitle,
      personalSubtitle: settings.personalSubtitle,
      hobbiesSubtitle: settings.hobbiesSubtitle,
    }
  }

  private async toError(response: Response): Promise<AdminAboutError> {
    const body = (await response.json().catch(() => ({}))) as ApiProblemBody

    if (404 === response.status) {
      return new AdminAboutError('not-found', 'About settings not found')
    }
    if (422 === response.status) {
      const reason: AdminAboutErrorReason = 'validation'
      const message = body.violations?.map((violation) => violation.message).join(' ') ?? body.detail ?? 'Validation failed'
      return new AdminAboutError(reason, message)
    }

    return new AdminAboutError('unknown', `Request failed with status ${response.status}`)
  }
}
