import type { WatchContent } from '../../domain/watch/entities/WatchContent'
import type { WatchRepository } from '../../domain/watch/repositories/WatchRepository'
import { WatchUnavailableError } from '../../domain/watch/errors/WatchUnavailableError'

/**
 * Implémentation HTTP de WatchRepository. Endpoint public (GET /api/watch, cf.
 * App\Portfolio\Watch).
 *
 * Aucun `credentials` : le défaut `same-origin` envoie les cookies sur une seule origine (préprod, prod), et le serveur
 * sert une route publique comme à l'anonyme quel que soit le cookie (#748, R35). Jamais `'omit'` : il retire aussi la
 * Basic Auth de la préprod (C1, `noOmittedCredentials.spec.ts`).
 *
 * Côté backend, cette route ne déclenche aucun appel sortant — elle sert un
 * instantané déjà calculé. La page ne dépend donc jamais de la disponibilité
 * d'endoflife.date au moment où un visiteur la charge.
 */
export class HttpWatchRepository implements WatchRepository {
  private readonly apiBaseUrl: string

  constructor(apiBaseUrl: string) {
    this.apiBaseUrl = apiBaseUrl
  }

  async get(): Promise<WatchContent> {
    const response = await fetch(`${this.apiBaseUrl}/api/watch`, { method: 'GET' })

    if (!response.ok) {
      throw new WatchUnavailableError()
    }

    return (await response.json()) as WatchContent
  }
}
