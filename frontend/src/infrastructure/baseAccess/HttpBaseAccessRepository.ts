import type { BaseAccessGrant } from '../../domain/baseAccess/entities/BaseAccessGrant'
import type { BaseAccessRepository } from '../../domain/baseAccess/repositories/BaseAccessRepository'
import { BaseAccessError } from '../../domain/baseAccess/errors/BaseAccessError'
import { LOGIN_CSRF_HEADER } from '../http/loginCsrfHeader'

interface BaseAccessResponseBody {
  roles?: string[]
  expiresAt?: string
}

/**
 * Implémentation HTTP de BaseAccessRepository (ADR 0003 D6). Pas de header
 * X-XSRF-TOKEN : cette route est explicitement exclue de la double-soumission
 * CSRF côté backend (CsrfCookieRequestSubscriber) — l'appelant est anonyme
 * par définition, il n'a aucun cookie XSRF-TOKEN préexistant. En revanche
 * X-Requested-With est requis (LOGIN_CSRF_HEADER, issue #76).
 *
 * `credentials: 'include'` reste : la réponse pose le cookie BEARER. Le pare-feu `api` lit le BEARER
 * envoyé sur cette route, mais un cookie refusé y est ignoré (route publique, #748, R35) : un seul appel, un seul
 * jeton du limiteur (20 par heure et par IP), pas de rejeu. Les échecs se lisent tels quels : 429 `rate-limited`,
 * tout autre `unknown`.
 */
export class HttpBaseAccessRepository implements BaseAccessRepository {
  private readonly apiBaseUrl: string

  constructor(apiBaseUrl: string) {
    this.apiBaseUrl = apiBaseUrl
  }

  async grant(): Promise<BaseAccessGrant> {
    const response = await this.post()

    if (!response.ok) {
      if (429 === response.status) {
        throw new BaseAccessError('rate-limited', 'Too many attempts')
      }
      throw new BaseAccessError('unknown', `Request failed with status ${response.status}`)
    }

    // L'échéance est informative : un corps absent ou illisible ne doit pas
    // transformer un accès obtenu (le cookie est posé) en échec.
    const body = (await response.json().catch(() => ({}))) as BaseAccessResponseBody

    return { expiresAt: parseExpiresAt(body.expiresAt) }
  }

  private post(): Promise<Response> {
    return fetch(`${this.apiBaseUrl}/api/account/base-access`, {
      method: 'POST',
      credentials: 'include',
      headers: LOGIN_CSRF_HEADER,
    })
  }
}

function parseExpiresAt(value: string | undefined): Date | null {
  if (undefined === value) {
    return null
  }
  const date = new Date(value)

  return Number.isNaN(date.getTime()) ? null : date
}
