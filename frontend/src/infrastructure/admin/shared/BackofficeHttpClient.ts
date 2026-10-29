import { sessionAwareFetch, type SessionAwareResponse } from '../../http/sessionAwareFetch'

/** Forme RFC7807-ish renvoyée par API Platform sur les réponses d'erreur (cf. api_platform.yaml `error_formats`). */
export interface ApiProblemBody {
  /** Slug stable `/errors/<slug>` posé par les exceptions métier implémentant ProblemExceptionInterface — à privilégier sur `detail` (localisé, instable) pour discriminer un conflit. */
  type?: string
  detail?: string
  violations?: { propertyPath: string; message: string }[]
}

/** Réaction à une session finie du backoffice (voir `infrastructure/http/sessionAwareFetch.ts`), posée une fois à la racine de composition. */
export type BackofficeUnauthorizedHandler = () => void

let unauthorizedHandler: BackofficeUnauthorizedHandler | null = null

/**
 * Les réponses que le client a jugées fin de session à l'envoi. Un `WeakSet` de module plutôt qu'une
 * propriété : `Response` est un objet du navigateur qu'on ne marque pas, et chaque dépôt a son propre
 * client, alors que la réponse passe de l'un au traducteur d'erreur de l'autre.
 */
const sessionEndedResponses = new WeakSet<Response>()

/**
 * Enregistre (ou, avec `null`, retire) la réaction à une session finie du backoffice.
 * La couche infrastructure ne connaît ni la session ni le routeur : la racine
 * de composition (`main.ts`) lui fournit la réaction.
 */
export function setBackofficeUnauthorizedHandler(handler: BackofficeUnauthorizedHandler | null): void {
  unauthorizedHandler = handler
}

/**
 * Plomberie HTTP commune aux repositories admin (backoffice `/api/backoffice/*`) :
 * l'envoi (cookies, header CSRF, verdict « session finie ») est celui de
 * `infrastructure/http/sessionAwareFetch.ts`, qui porte la règle ; ce client y ajoute la
 * réaction globale du backoffice et le parsing RFC7807-ish des réponses d'erreur. Chaque
 * repository garde son propre mapping statut -> AdminXxxError (les codes 404/409/422 n'ont
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
   * Vrai pour une réponse que ce client a jugée fin de session à l'envoi : les dépôts le testent
   * en premier dans leur traducteur d'erreur et rendent la raison `session-ended`.
   */
  endedTheSession(response: Response): boolean {
    return sessionEndedResponses.has(response)
  }

  /**
   * Une session finie (règle : `infrastructure/http/sessionAwareFetch.ts`) appelle la réaction commune,
   * ici, plutôt que reprise dans chacun des dépôts. La réponse est rendue telle quelle dans tous les
   * cas, et retenue ici pour que `endedTheSession()` la reconnaisse ; un autre refus reste au dépôt.
   */
  private react(judged: SessionAwareResponse): Response {
    if (judged.endedTheSession) {
      sessionEndedResponses.add(judged.response)
      unauthorizedHandler?.()
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
