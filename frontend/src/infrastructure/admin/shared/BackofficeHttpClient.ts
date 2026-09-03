import { readCsrfToken } from '../../auth/csrfCookie'

/** Forme RFC7807-ish renvoyée par API Platform sur les réponses d'erreur (cf. api_platform.yaml `error_formats`). */
export interface ApiProblemBody {
  /** Slug stable `/errors/<slug>` posé par les exceptions métier implémentant ProblemExceptionInterface — à privilégier sur `detail` (localisé, instable) pour discriminer un conflit. */
  type?: string
  detail?: string
  violations?: { propertyPath: string; message: string }[]
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
    return fetch(`${this.apiBaseUrl}${path}`, {
      method: 'GET',
      credentials: 'include',
    })
  }

  async mutate(method: string, path: string, body?: unknown): Promise<Response> {
    const csrfToken = readCsrfToken()

    return fetch(`${this.apiBaseUrl}${path}`, {
      method,
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        ...(csrfToken ? { 'X-XSRF-TOKEN': csrfToken } : {}),
      },
      body: body ? JSON.stringify(body) : undefined,
    })
  }

  async parseProblem(response: Response): Promise<ApiProblemBody> {
    return (await response.json().catch(() => ({}))) as ApiProblemBody
  }
}

/** Message d'erreur 422 commun à tous les repositories admin : violations jointes, ou detail, ou repli générique. */
export function violationsMessage(body: ApiProblemBody, fallback = 'Validation failed'): string {
  return body.violations?.map((violation) => violation.message).join(' ') ?? body.detail ?? fallback
}
