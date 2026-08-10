import type { ContactRepository } from '../../domain/contact/repositories/ContactRepository'
import type { ContactMessageInput } from '../../domain/contact/entities/ContactMessageInput'
import { ContactSubmissionFailedError } from '../../domain/contact/errors/ContactSubmissionFailedError'

/**
 * Implémentation HTTP de ContactRepository. Contrairement à HttpAuthRepository,
 * l'endpoint (POST /api/contact) est public et n'implique aucune session : pas
 * de `credentials: 'include'` ni de header CSRF (cf. App\Contact\Presentation\ApiResource\ContactMessageResource
 * et son exclusion dans CsrfCookieRequestSubscriber côté backend).
 */
export class HttpContactRepository implements ContactRepository {
  private readonly apiBaseUrl: string

  constructor(apiBaseUrl: string) {
    this.apiBaseUrl = apiBaseUrl
  }

  async submit(input: ContactMessageInput): Promise<void> {
    const response = await fetch(`${this.apiBaseUrl}/api/contact`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        name: input.name,
        email: input.email,
        message: input.message,
        website: input.honeypot,
      }),
    })

    if (!response.ok) {
      throw new ContactSubmissionFailedError()
    }
  }
}
