import type { Contribution } from '../../domain/contributions/entities/Contribution'
import type { ContributionRepository } from '../../domain/contributions/repositories/ContributionRepository'
import type { Locale } from '../../domain/portfolio/entities/Locale'
import { ContributionsUnavailableError } from '../../domain/contributions/errors/ContributionsUnavailableError'

/**
 * Implémentation HTTP de ContributionRepository. Endpoint public (GET
 * /api/contributions/{locale}, cf. App\Portfolio\Contribution, aucune
 * restriction dans access_control côté backend) : pas de
 * `credentials: 'include'` nécessaire.
 */
export class HttpContributionRepository implements ContributionRepository {
  private readonly apiBaseUrl: string

  constructor(apiBaseUrl: string) {
    this.apiBaseUrl = apiBaseUrl
  }

  async list(locale: Locale): Promise<readonly Contribution[]> {
    const response = await fetch(`${this.apiBaseUrl}/api/contributions/${locale}`, { method: 'GET' })

    if (!response.ok) {
      throw new ContributionsUnavailableError()
    }

    return (await response.json()) as readonly Contribution[]
  }
}
