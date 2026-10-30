import type { AuthRepository } from '../../domain/auth/repositories/AuthRepository'
import type { AuthenticatedUser } from '../../domain/auth/entities/AuthenticatedUser'
import { InvalidCredentialsError } from '../../domain/auth/errors/InvalidCredentialsError'
import { LoginRateLimitedError } from '../../domain/auth/errors/LoginRateLimitedError'
import { ANONYMOUS_SESSION, BASE_ACCESS_SESSION, type AuthSession } from '../../domain/auth/entities/AuthSession'
import { sessionForUser } from '../../domain/auth/services/sessionForUser'
import { hasCsrfToken } from './csrfCookie'
import { sessionAwareFetch } from '../http/sessionAwareFetch'
import { LOGIN_CSRF_HEADER } from '../http/loginCsrfHeader'
import { retryAfterSeconds } from '../http/retryAfterSeconds'

/**
 * Le délai au bout duquel `me()` abandonne (#748, R49, CR5-7). 15 s plutôt que 10 : au-delà, une personne connectée sur
 * un réseau très lent passe pour anonyme et une route protégée la mène à la connexion ; le délai doit donc dépasser
 * tout `/api/me` qui finirait par répondre.
 */
export const ME_TIMEOUT_MS = 15_000

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
      headers: { 'Content-Type': 'application/json', ...LOGIN_CSRF_HEADER },
      body: JSON.stringify({ username, password }),
    })

    if (401 === response.status) {
      throw new InvalidCredentialsError()
    }
    // Le refus de `login_throttling` comme celui de la zone nginx `login`
    // (issue #399) : un statut suffit, le corps n'est pas lu. Le délai vient
    // de Retry-After, que seul le throttling de Symfony pose.
    if (429 === response.status) {
      throw new LoginRateLimitedError(retryAfterSeconds(response))
    }
    if (!response.ok) {
      throw new Error(`Login failed with status ${response.status}`)
    }

    const body = (await response.json()) as UserResponseBody

    return { username: body.user.username, roles: body.user.roles }
  }

  async logout(): Promise<void> {
    // Une écriture : elle part avec son jeton CSRF, posé par l'envoi partagé (une seule définition de « avec jeton »,
    // R12). Le verdict « session finie » n'a pas de sens ici — un 401 est un jeton déjà mort, que le logout voulait
    // justement effacer —, seul `ok` compte.
    const { response } = await sessionAwareFetch(`${this.apiBaseUrl}/api/logout`, { method: 'POST' })

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
   *
   * **La requête est bornée** (#748, R49, CR5-7) : la route `login`, le garde des routes protégées et les deux
   * relectures (`recheckEndedSession`, `rereadAfterRefusal`) attendent tous ce `/api/me` ; sans borne, un serveur qui
   * ne répond pas les tenait sans fin — et tenait pour toujours l'emplacement `singleFlight` de chaque relecture. Au
   * bout de `ME_TIMEOUT_MS` la requête est abandonnée et `me()` rejette (`AbortError`), comme sur un échec réseau :
   * l'appelant sait déjà s'en remettre. Un `AbortController` et un `setTimeout` plutôt que `AbortSignal.timeout()`,
   * dont la minuterie interne échappe aux faux minuteurs de Vitest. La minuterie est levée dans le `finally`, que la
   * réponse vienne, échoue ou que le corps tarde à se lire.
   */
  async me(): Promise<AuthSession> {
    const controller = new AbortController()
    const timer = setTimeout(() => controller.abort(), ME_TIMEOUT_MS)

    try {
      const response = await fetch(`${this.apiBaseUrl}/api/me`, {
        method: 'GET',
        credentials: 'include',
        signal: controller.signal,
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
    } finally {
      clearTimeout(timer)
    }
  }

  /** Un cookie `XSRF-TOKEN` vide ou indécodable compte pour absent : le client n'en enverrait aucun en-tête (`hasCsrfToken`). */
  hasWriteToken(): boolean {
    return hasCsrfToken()
  }
}
