import type { QualityContent } from '../../domain/quality/entities/QualityContent'
import type { QualityContentRepository } from '../../domain/quality/repositories/QualityContentRepository'
import type { Locale } from '../../domain/portfolio/entities/Locale'
import { QualityContentUnavailableError } from '../../domain/quality/errors/QualityContentUnavailableError'

/**
 * Implémentation HTTP de QualityContentRepository. Endpoint public (GET
 * /api/quality/{locale}, cf. App\Portfolio\Quality, aucune restriction dans
 * access_control côté backend).
 *
 * Aucun `credentials` : le défaut `same-origin` envoie les cookies sur une seule origine (préprod, prod), et le serveur
 * sert une route publique comme à l'anonyme quel que soit le cookie (#748, R35). Jamais `'omit'` : il retire aussi la
 * Basic Auth de la préprod (C1, `noOmittedCredentials.spec.ts`).
 */
export class HttpQualityContentRepository implements QualityContentRepository {
  private readonly apiBaseUrl: string

  constructor(apiBaseUrl: string) {
    this.apiBaseUrl = apiBaseUrl
  }

  async get(locale: Locale): Promise<QualityContent> {
    const response = await fetch(`${this.apiBaseUrl}/api/quality/${locale}`, { method: 'GET' })

    if (!response.ok) {
      throw new QualityContentUnavailableError()
    }

    return (await response.json()) as QualityContent
  }
}
