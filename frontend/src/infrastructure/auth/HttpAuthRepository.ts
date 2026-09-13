import type { AuthRepository } from '../../domain/auth/repositories/AuthRepository'
import type { AuthenticatedUser } from '../../domain/auth/entities/AuthenticatedUser'
import { InvalidCredentialsError } from '../../domain/auth/errors/InvalidCredentialsError'
import { ANONYMOUS_SESSION, BASE_ACCESS_SESSION, type AuthSession } from '../../domain/auth/entities/AuthSession'
import { sessionForUser } from '../../domain/auth/services/sessionForUser'
import { readCsrfToken } from './csrfCookie'

interface UserResponseBody {
  user: { username: string; roles: string[] }
}

/**
 * Implémentation HTTP de AuthRepository. Le JWT ne transite jamais par ce
 * code : il voyage dans un cookie httpOnly posé/lu directement par le
 * navigateur (voir backend App\Security\Jwt\LoginSuccessSubscriber), d'où
 * `credentials: 'include'` sur chaque appel plutôt qu'un header Authorization.
 */
export class HttpAuthRepository implements AuthRepository {
  private readonly apiBaseUrl: string

  constructor(apiBaseUrl: string) {
    this.apiBaseUrl = apiBaseUrl
  }

  async login(username: string, password: string): Promise<AuthenticatedUser> {
    const response = await fetch(`${this.apiBaseUrl}/api/login_check`, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username, password }),
    })

    if (401 === response.status) {
      throw new InvalidCredentialsError()
    }
    if (!response.ok) {
      throw new Error(`Login failed with status ${response.status}`)
    }

    const body = (await response.json()) as UserResponseBody

    return { username: body.user.username, roles: body.user.roles }
  }

  async logout(): Promise<void> {
    const csrfToken = readCsrfToken()

    const response = await fetch(`${this.apiBaseUrl}/api/logout`, {
      method: 'POST',
      credentials: 'include',
      headers: csrfToken ? { 'X-XSRF-TOKEN': csrfToken } : {},
    })

    if (!response.ok) {
      throw new Error(`Logout failed with status ${response.status}`)
    }
  }

  /**
   * Le code HTTP de /api/me porte le palier (ADR 0003 D1) : la route exige
   * ROLE_TRUSTED (security.yaml), donc 401 = aucun jeton valide, 403 = jeton
   * valide mais insuffisant — exactement le jeton D6 du palier de base, sans
   * compte derrière (BaseAccessControllerTest le pinne côté backend) —,
   * 200 = compte de confiance. Un 403 n'est donc pas une erreur ici.
   */
  async me(): Promise<AuthSession> {
    const response = await fetch(`${this.apiBaseUrl}/api/me`, {
      method: 'GET',
      credentials: 'include',
    })

    if (401 === response.status) {
      return ANONYMOUS_SESSION
    }
    if (403 === response.status) {
      return BASE_ACCESS_SESSION
    }
    if (!response.ok) {
      throw new Error(`Fetching current user failed with status ${response.status}`)
    }

    const body = (await response.json()) as UserResponseBody

    return sessionForUser({ username: body.user.username, roles: body.user.roles })
  }
}
