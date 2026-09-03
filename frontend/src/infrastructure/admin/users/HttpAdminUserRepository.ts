import type { AdminUser } from '../../../domain/admin/users/entities/AdminUser'
import type { AdminUserRepository } from '../../../domain/admin/users/repositories/AdminUserRepository'
import type { Locale } from '../../../domain/portfolio/entities/Locale'
import { AdminUserError, type AdminUserErrorReason } from '../../../domain/admin/users/errors/AdminUserError'
import { BackofficeHttpClient, violationsMessage } from '../shared/BackofficeHttpClient'

interface BackofficeUserApiResponse {
  id: number
  username: string
  email: string | null
  roles: string[]
  status: 'pending' | 'active'
}

/** Opération à l'origine d'une erreur : le code 409 n'a pas le même sens selon le cas. */
type AdminUserOperation = 'list' | 'invite' | 'setRole' | 'resend' | 'delete' | 'changePassword'

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
      throw await this.toError(response, 'list')
    }

    const users = (await response.json()) as BackofficeUserApiResponse[]

    return users.map((user) => this.toAdminUser(user))
  }

  async invite(email: string, locale: Locale): Promise<AdminUser> {
    const response = await this.mutate('POST', BASE_PATH, { email, locale }, 'invite')

    return this.toAdminUser((await response.json()) as BackofficeUserApiResponse)
  }

  async setSuperAdmin(id: number, grant: boolean): Promise<void> {
    await this.mutate('PUT', `${BASE_PATH}/${id}/roles`, { superAdmin: grant }, 'setRole')
  }

  async resendInvitation(id: number, locale: Locale): Promise<void> {
    await this.mutate('POST', `${BASE_PATH}/${id}/invitation`, { locale }, 'resend')
  }

  async remove(id: number): Promise<void> {
    await this.mutate('DELETE', `${BASE_PATH}/${id}`, undefined, 'delete')
  }

  /**
   * Réponse 204 sans corps (`output: false` côté backend) : le mot de passe ne
   * doit jamais être renvoyé, même haché — pas de `response.json()` à tenter ici.
   */
  async changePassword(id: number, newPassword: string): Promise<void> {
    await this.mutate('PUT', `${BASE_PATH}/${id}/password`, { password: newPassword }, 'changePassword')
  }

  private async mutate(
    method: string,
    path: string,
    body: unknown,
    operation: AdminUserOperation,
  ): Promise<Response> {
    const response = await this.client.mutate(method, path, body)

    if (!response.ok) {
      throw await this.toError(response, operation)
    }

    return response
  }

  private toAdminUser(user: BackofficeUserApiResponse): AdminUser {
    return { id: user.id, username: user.username, email: user.email, roles: user.roles, status: user.status }
  }

  private async toError(response: Response, operation: AdminUserOperation): Promise<AdminUserError> {
    const body = await this.client.parseProblem(response)
    const detail = body.detail ?? ''

    if (409 === response.status) {
      return new AdminUserError(this.conflictReason(operation, detail), detail || 'Conflict')
    }
    if (404 === response.status) {
      return new AdminUserError('not-found', detail || 'User not found')
    }
    if (422 === response.status) {
      const reason: AdminUserErrorReason = 'validation'
      return new AdminUserError(reason, violationsMessage(body))
    }

    return new AdminUserError('unknown', `Request failed with status ${response.status}`)
  }

  /**
   * Le backend renvoie 409 avec un simple `detail` textuel (pas de `type`
   * distinct). On tranche d'abord par l'opération ; pour le changement de rôle,
   * qui a deux gardes, on retombe sur un fragment stable du message.
   */
  private conflictReason(operation: AdminUserOperation, detail: string): AdminUserErrorReason {
    if ('invite' === operation) {
      return 'email-taken'
    }
    if ('resend' === operation) {
      return 'already-activated'
    }
    if ('setRole' === operation) {
      return detail.includes('propres rôles') ? 'cannot-modify-own-roles' : 'cannot-demote-last-super'
    }

    return 'cannot-delete-self'
  }
}
