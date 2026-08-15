import type { AdminUser } from '../../../domain/admin/users/entities/AdminUser'
import type { AdminUserRepository } from '../../../domain/admin/users/repositories/AdminUserRepository'
import { AdminUserError, type AdminUserErrorReason } from '../../../domain/admin/users/errors/AdminUserError'
import { BackofficeHttpClient, violationsMessage } from '../shared/BackofficeHttpClient'

interface BackofficeUserApiResponse {
  id: number
  username: string
  roles: string[]
}

const BASE_PATH = '/api/backoffice/users'

/**
 * Implémentation HTTP de AdminUserRepository, sur BackofficeHttpClient (cookie
 * httpOnly BEARER + header CSRF sur les mutations, cf.
 * HttpAuthRepository.logout() / backend CsrfCookieRequestSubscriber).
 */
export class HttpAdminUserRepository implements AdminUserRepository {
  private readonly client: BackofficeHttpClient

  constructor(apiBaseUrl: string) {
    this.client = new BackofficeHttpClient(apiBaseUrl)
  }

  async list(): Promise<readonly AdminUser[]> {
    const response = await this.client.get(BASE_PATH)

    if (!response.ok) {
      throw await this.toError(response)
    }

    const users = (await response.json()) as BackofficeUserApiResponse[]

    return users.map((user) => ({ id: user.id, username: user.username, roles: user.roles }))
  }

  async remove(id: number): Promise<void> {
    await this.mutate('DELETE', `${BASE_PATH}/${id}`)
  }

  /**
   * Réponse 204 sans corps (`output: false` côté backend, cf.
   * BackofficeUserPasswordResource) : le mot de passe ne doit jamais être
   * renvoyé, même haché — pas de `response.json()` à tenter ici.
   */
  async changePassword(id: number, newPassword: string): Promise<void> {
    await this.mutate('PUT', `${BASE_PATH}/${id}/password`, { password: newPassword })
  }

  private async mutate(method: string, path: string, body?: unknown): Promise<Response> {
    const response = await this.client.mutate(method, path, body)

    if (!response.ok) {
      throw await this.toError(response)
    }

    return response
  }

  private async toError(response: Response): Promise<AdminUserError> {
    const body = await this.client.parseProblem(response)

    if (409 === response.status) {
      return new AdminUserError('cannot-delete-self', body.detail ?? 'Cannot delete own account')
    }
    if (404 === response.status) {
      return new AdminUserError('not-found', body.detail ?? 'User not found')
    }
    if (422 === response.status) {
      const reason: AdminUserErrorReason = 'validation'
      return new AdminUserError(reason, violationsMessage(body))
    }

    return new AdminUserError('unknown', `Request failed with status ${response.status}`)
  }
}
