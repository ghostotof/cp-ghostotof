import { readCsrfToken } from '../../auth/csrfCookie'
import { endsTheSession } from '../../http/sessionEnded'

/** Forme RFC7807-ish renvoyée par API Platform sur les réponses d'erreur (cf. api_platform.yaml `error_formats`). */
export interface ApiProblemBody {
  /** Slug stable `/errors/<slug>` posé par les exceptions métier implémentant ProblemExceptionInterface — à privilégier sur `detail` (localisé, instable) pour discriminer un conflit. */
  type?: string
  detail?: string
  violations?: { propertyPath: string; message: string }[]
}

/** Réaction à une session finie du backoffice (un 401, ou un 403 à une écriture sans jeton CSRF), posée une fois à la racine de composition. */
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
 * cookie httpOnly BEARER (`credentials: 'include'`), header CSRF X-XSRF-TOKEN
 * sur les mutations (double-submit cookie), et parsing RFC7807-ish des
 * réponses d'erreur. Chaque repository garde son propre mapping
 * statut -> AdminXxxError (les codes 404/409/422 n'ont pas la même
 * signification selon la ressource), seul le transport est partagé — et la
 * règle « session finie » (un 401, ou un 403 à une écriture sans jeton CSRF).
 */
export class BackofficeHttpClient {
  private readonly apiBaseUrl: string

  constructor(apiBaseUrl: string) {
    this.apiBaseUrl = apiBaseUrl
  }

  async get(path: string): Promise<Response> {
    // Le CSRF ne contrôle pas les lectures : un 403 à une lecture n'est jamais une session finie.
    return this.notifyIfSessionEnded(
      await fetch(`${this.apiBaseUrl}${path}`, {
        method: 'GET',
        credentials: 'include',
      }),
      false,
    )
  }

  async mutate(method: string, path: string, body?: unknown): Promise<Response> {
    const csrfToken = readCsrfToken()

    // Un cookie présent mais vide n'envoie pas d'en-tête (`csrfToken` est alors faux) : c'est « sans jeton ».
    return this.notifyIfSessionEnded(
      await fetch(`${this.apiBaseUrl}${path}`, {
        method,
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          ...(csrfToken ? { 'X-XSRF-TOKEN': csrfToken } : {}),
        },
        body: body ? JSON.stringify(body) : undefined,
      }),
      !csrfToken,
    )
  }

  /**
   * Vrai pour une réponse que ce client a jugée fin de session à l'envoi (`endsTheSession()`) : les
   * dépôts le testent en premier dans leur traducteur d'erreur et rendent la raison `session-ended`.
   */
  endedTheSession(response: Response): boolean {
    return sessionEndedResponses.has(response)
  }

  /**
   * Un 401, ou un 403 à une écriture partie sans jeton CSRF (`infrastructure/http/sessionEnded.ts`), est
   * une session finie, quelle qu'en soit la cause (JWT expiré, ouverte avant un changement de mot de
   * passe, déconnexion dans un autre onglet) : la réaction est commune, ici, plutôt que reprise dans
   * chacun des dépôts. Un 403 à une lecture, ou à une écriture qui portait son jeton (non autorisé,
   * signature CSRF refusée), reste au dépôt, qui le lit selon sa ressource. La réponse est rendue telle
   * quelle dans tous les cas, et retenue ici pour que `endedTheSession()` la reconnaisse.
   */
  private notifyIfSessionEnded(response: Response, sentWithoutCsrfToken: boolean): Response {
    if (endsTheSession(response.status, sentWithoutCsrfToken)) {
      sessionEndedResponses.add(response)
      unauthorizedHandler?.()
    }

    return response
  }

  async parseProblem(response: Response): Promise<ApiProblemBody> {
    return (await response.json().catch(() => ({}))) as ApiProblemBody
  }
}

/** Message d'erreur 422 commun à tous les repositories admin : violations jointes, ou detail, ou repli générique. */
export function violationsMessage(body: ApiProblemBody, fallback = 'Validation failed'): string {
  return body.violations?.map((violation) => violation.message).join(' ') ?? body.detail ?? fallback
}
