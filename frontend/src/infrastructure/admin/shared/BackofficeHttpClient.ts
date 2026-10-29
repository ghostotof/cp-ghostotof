import type { BackofficeSendStamp, BackofficeSessionReaction } from '../../../domain/admin/shared/BackofficeSessionReaction'
import { AccessForbiddenError } from '../../../domain/auth/errors/AccessForbiddenError'
import { SessionEndedError } from '../../../domain/auth/errors/SessionEndedError'
import type { ApiProblemBody } from '../../http/problemJson'
import { sessionAwareFetch, type SessionAwareResponse } from '../../http/sessionAwareFetch'

let sessionReaction: BackofficeSessionReaction | null = null

/**
 * Enregistre (ou, avec `null`, retire) la réaction du backoffice à ses réponses (`domain/admin/shared/`).
 * La couche infrastructure ne connaît ni la session ni le routeur : la racine de composition (`main.ts`) lui
 * fournit la réaction.
 */
export function setBackofficeSessionReaction(reaction: BackofficeSessionReaction | null): void {
  sessionReaction = reaction
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
    // Le timbre est lu avant l'envoi, jamais à la réception (voir `react`).
    const sentAt = sessionReaction?.stampAtSend('read')

    return this.react(await sessionAwareFetch(`${this.apiBaseUrl}${path}`, { method: 'GET' }), sentAt)
  }

  async mutate(method: string, path: string, body?: unknown): Promise<Response> {
    const sentAt = sessionReaction?.stampAtSend('write')

    return this.react(
      await sessionAwareFetch(`${this.apiBaseUrl}${path}`, {
        method,
        headers: { 'Content-Type': 'application/json' },
        body: body ? JSON.stringify(body) : undefined,
      }),
      sentAt,
    )
  }

  /**
   * Une session finie (règle : `infrastructure/http/sessionAwareFetch.ts`) appelle la réaction commune,
   * ici, une fois, avec le timbre lu à l'envoi ; si elle confirme que la session est finie, **lève**
   * `SessionEndedError` : aucun dépôt ne la traduit, elle traverse jusqu'aux composables, que `useAdminLoad` et
   * `visibleMutationError` (`application/admin/shared/`) taisent, le bandeau de session finie étant alors la seule
   * annonce. Si la réaction dit au contraire que la session a été rétablie depuis l'envoi (`ended` faux), la
   * réponse est d'une session révolue : elle est rendue au dépôt comme un refus ordinaire, et ne refinit rien.
   * Sans réaction enregistrée, `SessionEndedError` comme toujours. Un autre statut est rendu tel quel : le
   * dépôt lui donne le sens qu'il a pour sa ressource — **sauf un 403** (#748, R17) : une lecture, ou une écriture
   * qui portait son jeton, refusée, est un refus réel. Le client appelle `refused` (une fois) puis **lève**
   * `AccessForbiddenError`, que les composables taisent comme `SessionEndedError` : aucun dépôt n'a à le traduire, et
   * l'alerte d'`AdminLayout` est la seule annonce. Sans réaction enregistrée, il lève aussi.
   */
  private react(judged: SessionAwareResponse, sentAt: BackofficeSendStamp | undefined): Response {
    if (judged.endedTheSession) {
      const reaction = sessionReaction
      if (null !== reaction && undefined !== sentAt && !reaction.ended(sentAt)) {
        return judged.response
      }

      throw new SessionEndedError()
    }

    if (403 === judged.response.status) {
      // Le timbre de l'envoi : une lecture partie avant une navigation est jugée périmée par la réaction (CR3-8), pas une écriture (R38).
      if (undefined !== sentAt) {
        sessionReaction?.refused(sentAt)
      }

      throw new AccessForbiddenError()
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
