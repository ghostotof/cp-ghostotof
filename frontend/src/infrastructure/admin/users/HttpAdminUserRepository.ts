import type { AdminUser } from '../../../domain/admin/users/entities/AdminUser'
import type { AdminUserRepository } from '../../../domain/admin/users/repositories/AdminUserRepository'
import { AdminUserError, type AdminUserErrorReason } from '../../../domain/admin/users/errors/AdminUserError'
import { readCsrfToken } from '../../auth/csrfCookie'

interface BackofficeUserApiResponse {
  id: number
  username: string
  roles: string[]
}

/** Forme RFC7807-ish renvoyée par API Platform sur les réponses d'erreur (cf. api_platform.yaml `error_formats`). */
interface ApiProblemBody {
  detail?: string
  violations?: { propertyPath: string; message: string }[]
}

const BASE_PATH = '/api/backoffice/users'

/**
 * Implémentation HTTP de AdminUserRepository. Toutes les méthodes exigent le
 * cookie httpOnly BEARER (`credentials: 'include'`) et les mutations le
 * header CSRF X-XSRF-TOKEN (double-submit cookie, cf.
 * HttpAuthRepository.logout() / backend CsrfCookieRequestSubscriber).
 */
export class HttpAdminUserRepository implements AdminUserRepository {
  private readonly apiBaseUrl: string

  constructor(apiBaseUrl: string) {
    this.apiBaseUrl = apiBaseUrl
  }

  async list(): Promise<readonly AdminUser[]> {
    const response = await fetch(`${this.apiBaseUrl}${BASE_PATH}`, {
      method: 'GET',
      credentials: 'include',
    })

    if (!response.ok) {
      throw await this.toError(response)
    }

    const users = (await response.json()) as BackofficeUserApiResponse[]

    return users.map((user) => ({ id: user.id, username: user.username, roles: user.roles }))
  }

  async remove(id: number): Promise<void> {
    const csrfToken = readCsrfToken()

    const response = await fetch(`${this.apiBaseUrl}${BASE_PATH}/${id}`, {
      method: 'DELETE',
      credentials: 'include',
      headers: csrfToken ? { 'X-XSRF-TOKEN': csrfToken } : {},
    })

    if (!response.ok) {
      throw await this.toError(response)
    }
  }

  /**
   * Réponse 204 sans corps (`output: false` côté backend, cf.
   * BackofficeUserPasswordResource) : le mot de passe ne doit jamais être
   * renvoyé, même haché — pas de `response.json()` à tenter ici.
   */
  async changePassword(id: number, newPassword: string): Promise<void> {
    const csrfToken = readCsrfToken()

    const response = await fetch(`${this.apiBaseUrl}${BASE_PATH}/${id}/password`, {
      method: 'PUT',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        ...(csrfToken ? { 'X-XSRF-TOKEN': csrfToken } : {}),
      },
      body: JSON.stringify({ password: newPassword }),
    })

    if (!response.ok) {
      throw await this.toError(response)
    }
  }

  private async toError(response: Response): Promise<AdminUserError> {
    const body = (await response.json().catch(() => ({}))) as ApiProblemBody

    if (409 === response.status) {
      return new AdminUserError('cannot-delete-self', body.detail ?? 'Cannot delete own account')
    }
    if (404 === response.status) {
      return new AdminUserError('not-found', body.detail ?? 'User not found')
    }
    if (422 === response.status) {
      const reason: AdminUserErrorReason = 'validation'
      const message = body.violations?.map((violation) => violation.message).join(' ') ?? body.detail ?? 'Validation failed'
      return new AdminUserError(reason, message)
    }

    return new AdminUserError('unknown', `Request failed with status ${response.status}`)
  }
}
