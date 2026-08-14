import type { Stat } from '../../domain/portfolio/entities/Stat'
import type { StatsRepository } from '../../domain/stats/repositories/StatsRepository'
import type { Locale } from '../../domain/portfolio/entities/Locale'
import { StatsUnavailableError } from '../../domain/stats/errors/StatsUnavailableError'

/**
 * Implémentation HTTP de StatsRepository. Endpoint public (GET
 * /api/stats/{locale}, cf. App\Portfolio\Stats, aucune restriction dans
 * access_control côté backend) : pas de `credentials: 'include'` nécessaire.
 * Réponse : un tableau JSON à plat, sans enveloppe.
 */
export class HttpStatsRepository implements StatsRepository {
  private readonly apiBaseUrl: string

  constructor(apiBaseUrl: string) {
    this.apiBaseUrl = apiBaseUrl
  }

  async list(locale: Locale): Promise<readonly Stat[]> {
    const response = await fetch(`${this.apiBaseUrl}/api/stats/${locale}`, { method: 'GET' })

    if (!response.ok) {
      throw new StatsUnavailableError()
    }

    return (await response.json()) as Stat[]
  }
}
