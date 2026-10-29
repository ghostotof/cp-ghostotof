import type { QualityContent } from '../../domain/quality/entities/QualityContent'
import type { QualityContentRepository } from '../../domain/quality/repositories/QualityContentRepository'
import type { Locale } from '../../domain/portfolio/entities/Locale'
import { QualityContentUnavailableError } from '../../domain/quality/errors/QualityContentUnavailableError'

/**
 * Implémentation HTTP de QualityContentRepository. Endpoint public (GET
 * /api/quality/{locale}, cf. App\Portfolio\Quality, aucune restriction dans
 * access_control côté backend).
 *
 * `credentials: 'omit'` (#748, R23), écrit : aucune identité n'est lue, et en production (une seule origine) le défaut
 * `same-origin` enverrait un `BEARER` mort, dont le 401 tomberait sur une page publique (en dev, deux origines : il
 * n'en enverrait pas, ce qui cachait le défaut). `credentialsAreExplicit.spec.ts` le garde.
 */
export class HttpQualityContentRepository implements QualityContentRepository {
  private readonly apiBaseUrl: string

  constructor(apiBaseUrl: string) {
    this.apiBaseUrl = apiBaseUrl
  }

  async get(locale: Locale): Promise<QualityContent> {
    const response = await fetch(`${this.apiBaseUrl}/api/quality/${locale}`, { method: 'GET', credentials: 'omit' })

    if (!response.ok) {
      throw new QualityContentUnavailableError()
    }

    return (await response.json()) as QualityContent
  }
}
