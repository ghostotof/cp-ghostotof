import type { Locale } from '../../domain/portfolio/entities/Locale'
import type { ConversationTurn } from '../../domain/assistant/entities/AssistantMessage'
import type { AssistantRepository } from '../../domain/assistant/repositories/AssistantRepository'
import { AssistantError, type AssistantErrorReason } from '../../domain/assistant/errors/AssistantError'
import { retryAfterSeconds } from '../http/retryAfterSeconds'
import { sessionAwareFetch, type SessionAwareResponse } from '../http/sessionAwareFetch'
import { ServerEventParser, type ServerEvent } from './serverEvents'

const PATH = '/api/assistant/answers'

/** Un AbortError vient du signal de la page : ce n'est pas un échec, on le relance tel quel. */
function isAbortError(error: unknown): boolean {
  return error instanceof DOMException && error.name === 'AbortError'
}

/**
 * Implémentation HTTP d'AssistantRepository : POST /api/assistant/answers, réponse
 * en `text/event-stream` lue avec `fetch` (EventSource ne sait ni POSTer ni envoyer
 * le header CSRF). L'envoi (cookies, CSRF) et le verdict « session finie » sont ceux de
 * `infrastructure/http/sessionAwareFetch.ts`, qui porte la règle.
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
    let judged: SessionAwareResponse
    try {
      judged = await sessionAwareFetch(`${this.apiBaseUrl}${PATH}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'text/event-stream' },
        body: JSON.stringify({ locale, messages }),
        signal,
      })
    } catch (error) {
      throw this.transportError(error)
    }

    const response = judged.response
    if (!response.ok) {
      throw this.httpError(judged)
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

  private httpError(judged: SessionAwareResponse): AssistantError {
    const reason = this.reasonFor(judged)

    return new AssistantError(reason, reason === 'rate-limited' ? retryAfterSeconds(judged.response) : null)
  }

  private reasonFor(judged: SessionAwareResponse): AssistantErrorReason {
    // Le verdict de la règle commune passe avant le statut ; tout autre 403 (le jeton était là)
    // est un refus réel (#748, #732 point 4).
    if (judged.endedTheSession) {
      return 'unauthenticated'
    }
    switch (judged.response.status) {
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
}
