import type { AdminAboutSettings } from '../../../domain/admin/about/entities/AdminAboutSettings'
import type {
  AdminAboutSettingsInput,
  AdminAboutSettingsRepository,
} from '../../../domain/admin/about/repositories/AdminAboutSettingsRepository'
import { AdminAboutError, type AdminAboutErrorReason } from '../../../domain/admin/about/errors/AdminAboutError'
import type { Locale } from '../../../domain/portfolio/entities/Locale'
import { BackofficeHttpClient, violationsMessage } from '../shared/BackofficeHttpClient'
import { supportedLocaleOf } from '../shared/supportedLocale'

interface BackofficeAboutSettingsApiResponse {
  locale: string
  siteEyebrow: string
  meEyebrow: string
  technicalSubtitle: string
  personalSubtitle: string
  hobbiesSubtitle: string
}

const BASE_PATH = '/api/backoffice/about/settings'

/**
 * Le backend n'émet que `fr` et `en` : une autre langue est une rupture du contrat de l'API, dite ici par la raison
 * `unknown` (#748, R214, Q1 (A) ; `supportedLocaleOf`), que la page montre comme son erreur habituelle.
 */
function unsupportedLocale(): AdminAboutError {
  return new AdminAboutError('unknown', 'Unsupported locale in response')
}

/**
 * Implémentation HTTP de AdminAboutSettingsRepository. La locale EST
 * l'identifiant de la ressource (pas de {id} numérique), même pattern
 * BackofficeHttpClient que HttpAdminExperienceTechnologyRepository.
 */
export class HttpAdminAboutSettingsRepository implements AdminAboutSettingsRepository {
  private readonly client: BackofficeHttpClient

  constructor(apiBaseUrl: string) {
    this.client = new BackofficeHttpClient(apiBaseUrl)
  }

  async get(locale: Locale): Promise<AdminAboutSettings> {
    const response = await this.client.get(`${BASE_PATH}/${locale}`)

    if (!response.ok) {
      throw await this.toError(response)
    }

    return this.toEntity((await response.json()) as BackofficeAboutSettingsApiResponse)
  }

  async update(locale: Locale, input: AdminAboutSettingsInput): Promise<AdminAboutSettings> {
    const response = await this.client.mutate('PUT', `${BASE_PATH}/${locale}`, { locale, ...input })

    if (!response.ok) {
      throw await this.toError(response)
    }

    return this.toEntity((await response.json()) as BackofficeAboutSettingsApiResponse)
  }

  private toEntity(settings: BackofficeAboutSettingsApiResponse): AdminAboutSettings {
    return {
      locale: supportedLocaleOf(settings.locale, unsupportedLocale),
      siteEyebrow: settings.siteEyebrow,
      meEyebrow: settings.meEyebrow,
      technicalSubtitle: settings.technicalSubtitle,
      personalSubtitle: settings.personalSubtitle,
      hobbiesSubtitle: settings.hobbiesSubtitle,
    }
  }

  private async toError(response: Response): Promise<AdminAboutError> {
    const body = await this.client.parseProblem(response)

    if (404 === response.status) {
      return new AdminAboutError('not-found', 'About settings not found')
    }
    if (422 === response.status) {
      const reason: AdminAboutErrorReason = 'validation'
      return new AdminAboutError(reason, violationsMessage(body))
    }

    return new AdminAboutError('unknown', `Request failed with status ${response.status}`)
  }
}
