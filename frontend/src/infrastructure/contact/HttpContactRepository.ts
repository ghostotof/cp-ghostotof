import type { ContactRepository } from '../../domain/contact/repositories/ContactRepository'
import type { ContactMessageInput } from '../../domain/contact/entities/ContactMessageInput'
import { ContactSubmissionFailedError } from '../../domain/contact/errors/ContactSubmissionFailedError'
import { ContactRateLimitedError } from '../../domain/contact/errors/ContactRateLimitedError'
import { ContactValidationError, type ContactViolation } from '../../domain/contact/errors/ContactValidationError'

/** 422 : saisie refusée par le Validator, corps problem+json avec `violations[]`. */
const VALIDATION_STATUS = 422
/**
 * 429 : limitation de débit, rien à corriger dans la saisie.
 *
 * Limite connue et acceptée : seul le 429 du limiteur **applicatif** Symfony
 * (`contact_form`, 5/h par IP) arrive jusqu'ici. Celui de la zone nginx
 * `contact` est émis par nginx sans passer par PHP, donc sans en-têtes CORS ;
 * en production le front est servi depuis une autre origine que l'API, le
 * navigateur rejette la réponse et `fetch` lève un `TypeError` — ce cas
 * retombe donc sur ContactSubmissionFailedError. Le limiteur applicatif étant
 * bien plus bas que la zone nginx, il tombe le premier de toute façon.
 */
const RATE_LIMITED_STATUS = 429

/**
 * Implémentation HTTP de ContactRepository. Contrairement à HttpAuthRepository,
 * l'endpoint (POST /api/contact) est public et n'implique aucune session : pas
 * de `credentials: 'include'` ni de header CSRF (cf. App\Contact\Presentation\ApiResource\ContactMessageResource
 * et son exclusion dans CsrfCookieRequestSubscriber côté backend).
 *
 * Le mapping statut -> erreur est ici, et pas dans la page : la présentation
 * n'a pas à connaître le transport. Elle reçoit trois cas distincts parce que
 * la conduite à tenir diffère (corriger, attendre, réessayer plus tard).
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

    if (response.ok) {
      return
    }

    if (RATE_LIMITED_STATUS === response.status) {
      throw new ContactRateLimitedError()
    }

    if (VALIDATION_STATUS === response.status) {
      throw new ContactValidationError(await readViolations(response))
    }

    throw new ContactSubmissionFailedError()
  }
}

/**
 * Un 422 reste un 422 même si son corps est absent, tronqué ou d'une forme
 * inattendue : on ne dégrade pas alors vers « réessayez plus tard », qui serait
 * un mensonge. Sans violation exploitable, la page affiche un message de
 * validation générique.
 */
async function readViolations(response: Response): Promise<ContactViolation[]> {
  try {
    const body: unknown = await response.json()

    return extractViolations(body)
  } catch {
    return []
  }
}

function extractViolations(body: unknown): ContactViolation[] {
  if (null === body || 'object' !== typeof body) {
    return []
  }

  const violations = (body as Record<string, unknown>).violations

  if (!Array.isArray(violations)) {
    return []
  }

  return violations.filter(isViolation).map((violation) => ({
    propertyPath: violation.propertyPath,
    message: violation.message,
  }))
}

function isViolation(value: unknown): value is ContactViolation {
  if (null === value || 'object' !== typeof value) {
    return false
  }

  const candidate = value as Record<string, unknown>

  return 'string' === typeof candidate.propertyPath && 'string' === typeof candidate.message
}
