import { readCsrfToken } from '../../auth/csrfCookie'

/** Forme RFC7807-ish renvoyée par API Platform sur les réponses d'erreur (cf. api_platform.yaml `error_formats`). */
export interface ApiProblemBody {
  /** Slug stable `/errors/<slug>` posé par les exceptions métier implémentant ProblemExceptionInterface — à privilégier sur `detail` (localisé, instable) pour discriminer un conflit. */
  type?: string
  detail?: string
  violations?: { propertyPath: string; message: string }[]
}

/** Réaction à un 401 du backoffice, posée une fois à la racine de composition. */
export type BackofficeUnauthorizedHandler = () => void

let unauthorizedHandler: BackofficeUnauthorizedHandler | null = null

/**
 * Enregistre (ou, avec `null`, retire) la réaction à un 401 du backoffice.
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
 * signification selon la ressource), seul le transport est partagé.
 */
export class BackofficeHttpClient {
  private readonly apiBaseUrl: string

  constructor(apiBaseUrl: string) {
    this.apiBaseUrl = apiBaseUrl
  }

  async get(path: string): Promise<Response> {
    return this.notifyIfUnauthorized(
      await fetch(`${this.apiBaseUrl}${path}`, {
        method: 'GET',
        credentials: 'include',
      }),
    )
  }

  async mutate(method: string, path: string, body?: unknown): Promise<Response> {
    const csrfToken = readCsrfToken()

    return this.notifyIfUnauthorized(
      await fetch(`${this.apiBaseUrl}${path}`, {
        method,
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          ...(csrfToken ? { 'X-XSRF-TOKEN': csrfToken } : {}),
        },
        body: body ? JSON.stringify(body) : undefined,
      }),
    )
  }

  /**
   * Un 401 sur le backoffice est une session finie, quelle qu'en soit la cause
   * (JWT expiré, ouverte avant un changement de mot de passe) : la réaction est
   * commune, ici, plutôt que reprise dans chacun des dépôts. Un 403 (non
   * autorisé, jeton CSRF refusé) n'est pas un 401 et reste au dépôt, qui le lit
   * selon sa ressource. La réponse est rendue telle quelle dans les deux cas.
   */
  private notifyIfUnauthorized(response: Response): Response {
    if (401 === response.status) {
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
