import type { WatchSnapshot } from '../../domain/watch/entities/WatchSnapshot'
import type { WatchRepository } from '../../domain/watch/repositories/WatchRepository'
import { WatchUnavailableError } from '../../domain/watch/errors/WatchUnavailableError'

/**
 * Implémentation HTTP de WatchRepository. Endpoint public (GET /api/watch, cf.
 * App\Portfolio\Watch) : pas de `credentials: 'include'` nécessaire.
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

  async get(): Promise<WatchSnapshot> {
    const response = await fetch(`${this.apiBaseUrl}/api/watch`, { method: 'GET' })

    if (!response.ok) {
      throw new WatchUnavailableError()
    }

    return (await response.json()) as WatchSnapshot
  }
}
