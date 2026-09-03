import type { AccountRepository } from '../../domain/account/repositories/AccountRepository'
import { PasswordSetupLinkError } from '../../domain/account/errors/PasswordSetupLinkError'

/**
 * Implémentation HTTP de AccountRepository. Comme HttpContactRepository,
 * l'endpoint (`/api/account/password-setup/{token}`) est public : aucune
 * session, pas de `credentials: 'include'` ni de header CSRF (exclu du
 * double-submit côté backend, cf. CsrfCookieRequestSubscriber).
 */
export class HttpAccountRepository implements AccountRepository {
  private readonly apiBaseUrl: string

  constructor(apiBaseUrl: string) {
    this.apiBaseUrl = apiBaseUrl
  }

  async validateSetupToken(token: string): Promise<void> {
    const response = await fetch(this.endpoint(token), { method: 'GET' })

    if (!response.ok) {
      throw this.toError(response.status)
    }
  }

  async completePasswordSetup(token: string, password: string): Promise<void> {
    const response = await fetch(this.endpoint(token), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ password }),
    })

    if (!response.ok) {
      throw this.toError(response.status)
    }
  }

  private endpoint(token: string): string {
    return `${this.apiBaseUrl}/api/account/password-setup/${encodeURIComponent(token)}`
  }

  private toError(status: number): PasswordSetupLinkError {
    if (404 === status) {
      return new PasswordSetupLinkError('invalid', 'Invalid password setup link')
    }
    if (410 === status) {
      return new PasswordSetupLinkError('expired', 'Password setup link expired or already used')
    }
    if (422 === status) {
      return new PasswordSetupLinkError('weak-password', 'The password was rejected')
    }
    if (429 === status) {
      return new PasswordSetupLinkError('rate-limited', 'Too many attempts')
    }

    return new PasswordSetupLinkError('unknown', `Request failed with status ${status}`)
  }
}
