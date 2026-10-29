import type { Contribution } from '../../domain/contributions/entities/Contribution'
import type { ContributionRepository } from '../../domain/contributions/repositories/ContributionRepository'
import type { Locale } from '../../domain/portfolio/entities/Locale'
import { ContributionsUnavailableError } from '../../domain/contributions/errors/ContributionsUnavailableError'

/**
 * Implémentation HTTP de ContributionRepository. Endpoint public (GET
 * /api/contributions/{locale}, cf. App\Portfolio\Contribution, aucune
 * restriction dans access_control côté backend).
 *
 * `credentials: 'omit'` (#748, R23), écrit : aucune identité n'est lue, et en production (une seule origine) le défaut
 * `same-origin` enverrait un `BEARER` mort, dont le 401 tomberait sur une page publique (en dev, deux origines : il
 * n'en enverrait pas, ce qui cachait le défaut). `credentialsAreExplicit.spec.ts` le garde.
 */
export class HttpContributionRepository implements ContributionRepository {
  private readonly apiBaseUrl: string

  constructor(apiBaseUrl: string) {
    this.apiBaseUrl = apiBaseUrl
  }

  async list(locale: Locale): Promise<readonly Contribution[]> {
    const response = await fetch(`${this.apiBaseUrl}/api/contributions/${locale}`, { method: 'GET', credentials: 'omit' })

    if (!response.ok) {
      throw new ContributionsUnavailableError()
    }

    return (await response.json()) as readonly Contribution[]
  }
}
