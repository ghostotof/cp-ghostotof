import type { AccountRepository } from '../../domain/account/repositories/AccountRepository'
import { PasswordSetupLinkError } from '../../domain/account/errors/PasswordSetupLinkError'

/**
 * Implémentation HTTP de AccountRepository. Comme HttpContactRepository, les
 * deux endpoints sont publics : aucune session, pas de `credentials: 'include'`
 * ni de header CSRF (chemins exclus du double-submit côté backend, cf.
 * CsrfCookieRequestSubscriber).
 *
 * Le jeton voyage TOUJOURS dans le corps JSON, jamais dans l'URL (audit A7,
 * décision D6) : un chemin finit dans les access logs du sidecar nginx et de
 * l'ingress, un corps non. D'où un POST même pour la simple vérification —
 * ne pas revenir à un `GET …/{token}`.
 */
export class HttpAccountRepository implements AccountRepository {
  private readonly apiBaseUrl: string

  constructor(apiBaseUrl: string) {
    this.apiBaseUrl = apiBaseUrl
  }

  async validateSetupToken(token: string): Promise<void> {
    const response = await this.post('/api/account/password-setup/validate', { token })

    if (!response.ok) {
      // Ici le backend ne valide que le jeton (absent, vide, trop long) : un
      // 422 désigne donc un lien corrompu, pas un mot de passe refusé.
      throw this.toError(response.status, 'invalid')
    }
  }

  async completePasswordSetup(token: string, password: string): Promise<void> {
    const response = await this.post('/api/account/password-setup', { token, password })

    if (!response.ok) {
      // Le jeton a déjà passé validate() : un 422 ne peut plus viser que le
      // mot de passe (longueur, mot de passe compromis…).
      throw this.toError(response.status, 'weak-password')
    }
  }

  private post(path: string, body: Record<string, string>): Promise<Response> {
    return fetch(`${this.apiBaseUrl}${path}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    })
  }

  /**
   * @param unprocessableReason sens d'un 422, qui dépend de l'endpoint appelé
   */
  private toError(status: number, unprocessableReason: 'invalid' | 'weak-password'): PasswordSetupLinkError {
    if (404 === status) {
      return new PasswordSetupLinkError('invalid', 'Invalid password setup link')
    }
    if (410 === status) {
      return new PasswordSetupLinkError('expired', 'Password setup link expired or already used')
    }
    if (422 === status) {
      return new PasswordSetupLinkError(
        unprocessableReason,
        'invalid' === unprocessableReason ? 'Malformed password setup link' : 'The password was rejected',
      )
    }
    if (429 === status) {
      return new PasswordSetupLinkError('rate-limited', 'Too many attempts')
    }

    return new PasswordSetupLinkError('unknown', `Request failed with status ${status}`)
  }
}
