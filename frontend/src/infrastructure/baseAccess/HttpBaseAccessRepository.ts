import type { BaseAccessRepository } from '../../domain/baseAccess/repositories/BaseAccessRepository'
import { BaseAccessError } from '../../domain/baseAccess/errors/BaseAccessError'

/**
 * Implémentation HTTP de BaseAccessRepository (ADR 0003 D6). Pas de header
 * X-XSRF-TOKEN : cette route est explicitement exclue de la double-soumission
 * CSRF côté backend (CsrfCookieRequestSubscriber) — l'appelant est anonyme
 * par définition, il n'a aucun cookie XSRF-TOKEN préexistant.
 */
export class HttpBaseAccessRepository implements BaseAccessRepository {
  private readonly apiBaseUrl: string

  constructor(apiBaseUrl: string) {
    this.apiBaseUrl = apiBaseUrl
  }

  async grant(): Promise<void> {
    const response = await fetch(`${this.apiBaseUrl}/api/account/base-access`, {
      method: 'POST',
      credentials: 'include',
    })

    if (!response.ok) {
      if (429 === response.status) {
        throw new BaseAccessError('rate-limited', 'Too many attempts')
      }
      throw new BaseAccessError('unknown', `Request failed with status ${response.status}`)
    }
  }
}
