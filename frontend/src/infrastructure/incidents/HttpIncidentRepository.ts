import type { Incident } from '../../domain/incidents/entities/Incident'
import type { IncidentRepository } from '../../domain/incidents/repositories/IncidentRepository'
import type { Locale } from '../../domain/portfolio/entities/Locale'
import { IncidentsUnavailableError } from '../../domain/incidents/errors/IncidentsUnavailableError'

/**
 * Implémentation HTTP de IncidentRepository. Endpoint public (GET
 * /api/incidents/{locale}, cf. App\Portfolio\Incident).
 *
 * `credentials: 'omit'` (#748, R23), écrit : aucune identité n'est lue, et en production (une seule origine) le défaut
 * `same-origin` enverrait un `BEARER` mort, dont le 401 tomberait sur une page publique (en dev, deux origines : il
 * n'en enverrait pas, ce qui cachait le défaut). `credentialsAreExplicit.spec.ts` le garde.
 */
export class HttpIncidentRepository implements IncidentRepository {
  private readonly apiBaseUrl: string

  constructor(apiBaseUrl: string) {
    this.apiBaseUrl = apiBaseUrl
  }

  async list(locale: Locale): Promise<readonly Incident[]> {
    const response = await fetch(`${this.apiBaseUrl}/api/incidents/${locale}`, { method: 'GET', credentials: 'omit' })

    if (!response.ok) {
      throw new IncidentsUnavailableError()
    }

    return (await response.json()) as readonly Incident[]
  }
}
