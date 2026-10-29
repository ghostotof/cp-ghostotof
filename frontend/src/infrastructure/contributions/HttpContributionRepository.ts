import type { Contribution } from '../../domain/contributions/entities/Contribution'
import type { ContributionRepository } from '../../domain/contributions/repositories/ContributionRepository'
import type { Locale } from '../../domain/portfolio/entities/Locale'
import { ContributionsUnavailableError } from '../../domain/contributions/errors/ContributionsUnavailableError'

/**
 * Implémentation HTTP de ContributionRepository. Endpoint public (GET
 * /api/contributions/{locale}, cf. App\Portfolio\Contribution, aucune
 * restriction dans access_control côté backend).
 *
 * Aucun `credentials` : le défaut `same-origin` envoie les cookies sur une seule origine (préprod, prod), et le serveur
 * sert une route publique comme à l'anonyme quel que soit le cookie (#748, R35). Jamais `'omit'` : il retire aussi la
 * Basic Auth de la préprod (C1, `noOmittedCredentials.spec.ts`).
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
