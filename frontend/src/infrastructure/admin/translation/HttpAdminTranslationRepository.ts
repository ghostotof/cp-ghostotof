import type { Locale } from '../../../domain/portfolio/entities/Locale'
import type { TranslationDraft, TranslationFields } from '../../../domain/admin/translation/entities/TranslationDraft'
import type { AdminTranslationRepository } from '../../../domain/admin/translation/repositories/AdminTranslationRepository'
import {
  AdminTranslationError,
  type AdminTranslationErrorReason,
} from '../../../domain/admin/translation/errors/AdminTranslationError'
import { BackofficeHttpClient, violationsMessage } from '../shared/BackofficeHttpClient'

interface BackofficeTranslationApiResponse {
  sourceLocale: Locale
  targetLocale: Locale
  fields: Record<string, string>
}

const PATH = '/api/backoffice/translations'

/**
 * Implémentation HTTP d'AdminTranslationRepository : POST /api/backoffice/translations,
 * cookie httpOnly BEARER et header CSRF (cf. BackofficeHttpClient). Le mapping
 * statut → raison est propre à cette ressource : 429 et 503 y ont un sens
 * précis (quota du compte, assistant indisponible) que le client doit
 * distinguer pour afficher le bon message.
 */
export class HttpAdminTranslationRepository implements AdminTranslationRepository {
  private readonly client: BackofficeHttpClient

  constructor(apiBaseUrl: string) {
    this.client = new BackofficeHttpClient(apiBaseUrl)
  }

  async translate(sourceLocale: Locale, targetLocale: Locale, fields: TranslationFields): Promise<TranslationDraft> {
    const response = await this.client.mutate('POST', PATH, { sourceLocale, targetLocale, fields })

    if (!response.ok) {
      throw await this.toError(response)
    }

    const body = (await response.json()) as BackofficeTranslationApiResponse

    return { sourceLocale: body.sourceLocale, targetLocale: body.targetLocale, fields: body.fields }
  }

  private async toError(response: Response): Promise<AdminTranslationError> {
    const body = await this.client.parseProblem(response)
    const reason = this.reasonFor(response.status)

    return new AdminTranslationError(reason, violationsMessage(body, 'Translation failed'))
  }

  private reasonFor(status: number): AdminTranslationErrorReason {
    switch (status) {
      case 422:
        return 'validation'
      case 429:
        return 'rate-limited'
      case 503:
        return 'unavailable'
      default:
        return 'unknown'
    }
  }
}
