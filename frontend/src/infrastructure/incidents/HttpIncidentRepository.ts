import type { Incident } from '../../domain/incidents/entities/Incident'
import type { IncidentRepository } from '../../domain/incidents/repositories/IncidentRepository'
import type { Locale } from '../../domain/portfolio/entities/Locale'
import { IncidentsUnavailableError } from '../../domain/incidents/errors/IncidentsUnavailableError'

/**
 * Implémentation HTTP de IncidentRepository. Endpoint public (GET
 * /api/incidents/{locale}, cf. App\Portfolio\Incident) : pas de
 * `credentials: 'include'` nécessaire.
 */
export class HttpIncidentRepository implements IncidentRepository {
  private readonly apiBaseUrl: string

  constructor(apiBaseUrl: string) {
    this.apiBaseUrl = apiBaseUrl
  }

  async list(locale: Locale): Promise<readonly Incident[]> {
    const response = await fetch(`${this.apiBaseUrl}/api/incidents/${locale}`, { method: 'GET' })

    if (!response.ok) {
      throw new IncidentsUnavailableError()
    }

    return (await response.json()) as readonly Incident[]
  }
}
