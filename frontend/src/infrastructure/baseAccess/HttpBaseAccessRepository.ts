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
 * `credentials: 'include'` reste : la réponse pose le cookie BEARER. Revers : le
 * pare-feu `api` lit le BEARER envoyé sur cette route, donc un cookie mort (JWT
 * expiré, réutilisé après un rechargement) y prend le 401 de Lexik. Ce 401 efface
 * les deux cookies (StaleSessionCookiesClearingListener) : le second essai part
 * donc sans cookie et aboutit. Un rejeu, jamais deux, et seulement sur 401 : un
 * 429, un 403 ou une panne réseau n'ont rien à voir avec un cookie mort (#748, R24).
 * Prix accepté : le limiteur (priorité 15) passe avant le pare-feu (8) et consomme
 * un jeton par tentative, soit deux jetons du quota (20 par heure et par IP) dans
 * ce cas. Écarté : un pare-feu sans authentificateur pour cette route, qui
 * supprimerait le 401 mais touche security.yaml et quatre gardes (ticket `__Host-`).
 */
export class HttpBaseAccessRepository implements BaseAccessRepository {
  private readonly apiBaseUrl: string

  constructor(apiBaseUrl: string) {
    this.apiBaseUrl = apiBaseUrl
  }

  async grant(): Promise<BaseAccessGrant> {
    let response = await this.post()

    // Un 401 ici = un BEARER mort que ce 401 vient d'effacer : un seul second essai.
    if (401 === response.status) {
      response = await this.post()
    }

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
