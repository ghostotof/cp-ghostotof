import { SessionEndedError } from '../../../domain/auth/errors/SessionEndedError'
import type { ApiProblemBody } from '../../http/problemJson'
import { sessionAwareFetch, type SessionAwareResponse } from '../../http/sessionAwareFetch'

/** Réaction à une session finie du backoffice (voir `infrastructure/http/sessionAwareFetch.ts`), posée une fois à la racine de composition. */
export type BackofficeSessionEndedHandler = () => void

let sessionEndedHandler: BackofficeSessionEndedHandler | null = null

/**
 * Enregistre (ou, avec `null`, retire) la réaction à une session finie du backoffice.
 * La couche infrastructure ne connaît ni la session ni le routeur : la racine
 * de composition (`main.ts`) lui fournit la réaction.
 */
export function setBackofficeSessionEndedHandler(handler: BackofficeSessionEndedHandler | null): void {
  sessionEndedHandler = handler
}

/**
 * Plomberie HTTP commune aux repositories admin (backoffice `/api/backoffice/*`) :
 * l'envoi (cookies, header CSRF, verdict « session finie ») est celui de
 * `infrastructure/http/sessionAwareFetch.ts`, qui porte la règle ; ce client y ajoute la
 * réaction globale du backoffice, la levée de `SessionEndedError` et le parsing RFC7807-ish
 * des réponses d'erreur. Chaque repository garde son propre mapping statut -> AdminXxxError (les codes 404/409/422 n'ont
 * pas la même signification selon la ressource).
 */
export class BackofficeHttpClient {
  private readonly apiBaseUrl: string

  constructor(apiBaseUrl: string) {
    this.apiBaseUrl = apiBaseUrl
  }

  async get(path: string): Promise<Response> {
    return this.react(await sessionAwareFetch(`${this.apiBaseUrl}${path}`, { method: 'GET' }))
  }

  async mutate(method: string, path: string, body?: unknown): Promise<Response> {
    return this.react(
      await sessionAwareFetch(`${this.apiBaseUrl}${path}`, {
        method,
        headers: { 'Content-Type': 'application/json' },
        body: body ? JSON.stringify(body) : undefined,
      }),
    )
  }

  /**
   * Une session finie (règle : `infrastructure/http/sessionAwareFetch.ts`) appelle la réaction commune,
   * ici, une fois, puis **lève** `SessionEndedError` : aucun dépôt ne la traduit, elle traverse jusqu'aux
   * composables, que `useAdminLoad` et `visibleMutationError` (`application/admin/shared/`) taisent, le
   * bandeau de session finie étant alors la seule annonce. Un autre refus est rendu tel quel : le
   * dépôt lui donne le sens qu'il a pour sa ressource.
   */
  private react(judged: SessionAwareResponse): Response {
    if (judged.endedTheSession) {
      sessionEndedHandler?.()

      throw new SessionEndedError()
    }

    return judged.response
  }

  async parseProblem(response: Response): Promise<ApiProblemBody> {
    return (await response.json().catch(() => ({}))) as ApiProblemBody
  }
}

/** Message d'erreur 422 commun à tous les repositories admin : violations jointes, ou detail, ou repli générique. */
export function violationsMessage(body: ApiProblemBody, fallback = 'Validation failed'): string {
  return body.violations?.map((violation) => violation.message).join(' ') ?? body.detail ?? fallback
}
