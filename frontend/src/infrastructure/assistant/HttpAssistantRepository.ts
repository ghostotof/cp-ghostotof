import type { Locale } from '../../domain/portfolio/entities/Locale'
import type { ConversationTurn } from '../../domain/assistant/entities/AssistantMessage'
import type { AssistantRepository } from '../../domain/assistant/repositories/AssistantRepository'
import { AssistantError, type AssistantErrorReason } from '../../domain/assistant/errors/AssistantError'
import { readCsrfToken } from '../auth/csrfCookie'
import { ServerEventParser, type ServerEvent } from './serverEvents'

const PATH = '/api/assistant/answers'

/** Un AbortError vient du signal de la page : ce n'est pas un échec, on le relance tel quel. */
function isAbortError(error: unknown): boolean {
  return error instanceof DOMException && error.name === 'AbortError'
}

/**
 * Implémentation HTTP d'AssistantRepository : POST /api/assistant/answers, réponse
 * en `text/event-stream` lue avec `fetch` (EventSource ne sait ni POSTer ni envoyer
 * le header CSRF). Cookie httpOnly BEARER + double-submit CSRF, comme le backoffice.
 *
 * Le flux est `delta` {text}* puis exactement un `done` ou `error`. Un flux qui se
 * ferme sans l'un des deux (coupure réseau, #318) est une erreur `network` : sans
 * cela, la page laisserait le message « en cours » indéfiniment.
 */
export class HttpAssistantRepository implements AssistantRepository {
  private readonly apiBaseUrl: string

  constructor(apiBaseUrl: string) {
    this.apiBaseUrl = apiBaseUrl
  }

  async answer(
    locale: Locale,
    messages: readonly ConversationTurn[],
    onDelta: (text: string) => void,
    signal?: AbortSignal,
  ): Promise<void> {
    const csrfToken = readCsrfToken()

    let response: Response
    try {
      response = await fetch(`${this.apiBaseUrl}${PATH}`, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'text/event-stream',
          ...(csrfToken ? { 'X-XSRF-TOKEN': csrfToken } : {}),
        },
        body: JSON.stringify({ locale, messages }),
        signal,
      })
    } catch (error) {
      throw this.transportError(error)
    }

    if (!response.ok) {
      throw this.httpError(response)
    }
    if (response.body === null) {
      throw new AssistantError('network')
    }

    await this.consume(response.body, onDelta)
  }

  /** Lit le flux jusqu'à `done` (résout) ; tout autre issue rejette. */
  private async consume(body: ReadableStream<Uint8Array>, onDelta: (text: string) => void): Promise<void> {
    const reader = body.getReader()
    // `stream: true` garde en mémoire un caractère multi-octets coupé entre deux morceaux.
    const decoder = new TextDecoder('utf-8')
    const parser = new ServerEventParser()

    try {
      for (;;) {
        let chunk: ReadableStreamReadResult<Uint8Array>
        try {
          chunk = await reader.read()
        } catch (error) {
          throw this.transportError(error)
        }

        if (chunk.done) {
          // Fin du flux sans `done` ni `error` : interrompu.
          throw new AssistantError('network')
        }

        for (const event of parser.push(decoder.decode(chunk.value, { stream: true }))) {
          if (this.handle(event, onDelta)) {
            return
          }
        }
      }
    } finally {
      // Ferme la connexion si on sort avant la fin (done, erreur) ; sans effet sinon.
      await reader.cancel().catch(() => undefined)
    }
  }

  /** @returns `true` quand l'événement est `done` (le flux est terminé). */
  private handle(event: ServerEvent, onDelta: (text: string) => void): boolean {
    switch (event.type) {
      case 'delta':
        onDelta(this.parseDelta(event.data))
        return false
      case 'done':
        return true
      case 'error':
        // La raison fournie par le serveur n'est pas exposée : la page n'a qu'un message.
        throw new AssistantError('unavailable')
      default:
        return false
    }
  }

  private parseDelta(data: string): string {
    let parsed: unknown
    try {
      parsed = JSON.parse(data)
    } catch {
      throw new AssistantError('unknown')
    }

    const text = (parsed as { text?: unknown } | null)?.text
    if (typeof text !== 'string') {
      throw new AssistantError('unknown')
    }

    return text
  }

  private transportError(error: unknown): unknown {
    return isAbortError(error) ? error : new AssistantError('network')
  }

  private httpError(response: Response): AssistantError {
    const reason = this.reasonFor(response.status)

    return new AssistantError(reason, reason === 'rate-limited' ? this.retryAfter(response) : null)
  }

  private reasonFor(status: number): AssistantErrorReason {
    switch (status) {
      case 401:
        return 'unauthenticated'
      case 403:
        return 'forbidden'
      case 413:
        return 'too-large'
      case 422:
        return 'validation'
      case 429:
        return 'rate-limited'
      case 503:
        return 'unavailable'
      default:
        return 'unknown'
    }
  }

  /** `Retry-After` : des secondes entières, ou une date HTTP. Absent (zone nginx) ou illisible → null. */
  private retryAfter(response: Response): number | null {
    const header = response.headers.get('Retry-After')?.trim()
    if (!header) {
      return null
    }
    if (/^\d+$/.test(header)) {
      return Number(header)
    }

    const date = Date.parse(header)

    return Number.isNaN(date) ? null : Math.max(0, Math.ceil((date - Date.now()) / 1000))
  }
}
