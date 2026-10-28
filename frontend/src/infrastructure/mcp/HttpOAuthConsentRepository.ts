import { NOMINATIVE_READ_SCOPE, type ConsentParams, type ConsentRequest } from '../../domain/mcp/entities/ConsentRequest'
import type { OAuthConsentRepository } from '../../domain/mcp/repositories/OAuthConsentRepository'
import { consentQuery } from '../../domain/mcp/services/consentParams'
import { ConsentFailedError } from '../../domain/mcp/errors/ConsentFailedError'
import { InvalidConsentRequestError } from '../../domain/mcp/errors/InvalidConsentRequestError'
import { MalformedConsentDecisionError } from '../../domain/mcp/errors/MalformedConsentDecisionError'
import { ReauthenticationRequiredError } from '../../domain/mcp/errors/ReauthenticationRequiredError'
import { SessionExpiredError } from '../../domain/mcp/errors/SessionExpiredError'
import { readCsrfToken } from '../auth/csrfCookie'
import type { ApiProblemBody } from '../admin/shared/BackofficeHttpClient'
import { hasProblemType } from '../admin/shared/orderProblems'

const PATH = '/api/oauth/consent'

/**
 * Le slug du 400 que le serveur rend quand il refuse la demande à la
 * revalidation (`AuthorizationRequestRejectedException`). Reconnu par le `type`
 * du problem+json, jamais par le `detail` localisé, comme les autres slugs du
 * frontend (`orderProblems.ts`) : un autre 400 — corps JSON illisible, par
 * exemple — n'est pas « recommencez depuis votre assistant ». Renommé côté
 * backend, il se renomme ici dans le même changement.
 */
export const AUTHORIZATION_REQUEST_REJECTED = 'authorization-request-rejected'

/**
 * Le slug du 401 que le serveur rend quand l'authentification de la personne
 * est trop ancienne pour accorder une connexion
 * (`ReauthenticationRequiredException`, #647). Reconnu par le `type`, comme
 * le précédent : tout autre 401 reste une session absente ou expirée.
 */
export const REAUTHENTICATION_REQUIRED = 'reauthentication-required'

/** Ce que veut dire un 422 : sur la décision, un corps que la page a mal formé. */
type UnprocessableMeaning = 'malformed-decision' | 'generic'

/**
 * Implémentation HTTP d'OAuthConsentRepository : `GET` puis `POST
 * /api/oauth/consent` (#647). Cookie httpOnly BEARER (`credentials:
 * 'include'`) sur les deux, double-submit CSRF (`X-XSRF-TOKEN`) sur le `POST`,
 * comme toute mutation de l'API.
 *
 * Le `POST` répond un 200 JSON portant l'URL de retour, jamais un 302 : `fetch`
 * suivrait la redirection au lieu de la rendre, vers le client, hors de
 * l'origine du site. La page la suit elle-même, après en avoir vérifié le
 * schéma.
 *
 * Le corps du résumé est vérifié champ par champ : une réponse d'une autre
 * forme (version du backend désynchronisée, proxy qui répond du HTML) est un
 * échec, plutôt qu'une page qui afficherait `undefined` à la place du client.
 * Le scope est fixé à `nominative:read`, le seul que le texte de la page décrit.
 * Le compte qui accorde (#650) est exigé aussi : le serveur le lit sur le cookie
 * de la requête, la page ne le déduit de rien.
 */
export class HttpOAuthConsentRepository implements OAuthConsentRepository {
  private readonly apiBaseUrl: string

  constructor(apiBaseUrl: string) {
    this.apiBaseUrl = apiBaseUrl
  }

  async describe(params: ConsentParams): Promise<ConsentRequest> {
    const query = new URLSearchParams(consentQuery(params)).toString()
    const response = await this.send(
      `${this.apiBaseUrl}${PATH}?${query}`,
      { method: 'GET', credentials: 'include', headers: { Accept: 'application/json' } },
      // Un GET n'a pas de corps : un 422 n'y a pas de sens propre.
      'generic',
    )

    return this.parseSummary(await this.readJson(response))
  }

  async decide(params: ConsentParams, approved: boolean): Promise<string> {
    const csrfToken = readCsrfToken()
    const response = await this.send(
      `${this.apiBaseUrl}${PATH}`,
      {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          ...(csrfToken ? { 'X-XSRF-TOKEN': csrfToken } : {}),
        },
        // Les paramètres reçus, tels quels (un absent part en `null`), plus la décision.
        body: JSON.stringify({ ...params, approved }),
      },
      'malformed-decision',
    )

    const redirectUri = (await this.readJson(response) as { redirectUri?: unknown } | null)?.redirectUri
    if ('string' !== typeof redirectUri) {
      throw new ConsentFailedError('The consent response carries no redirect URI')
    }

    return redirectUri
  }

  /** Appel réseau ; un statut d'erreur devient l'exception du domaine qui lui correspond. */
  private async send(url: string, init: RequestInit, unprocessable: UnprocessableMeaning): Promise<Response> {
    let response: Response
    try {
      response = await fetch(url, init)
    } catch {
      throw new ConsentFailedError('The consent request could not reach the server')
    }

    if (!response.ok) {
      throw await this.httpError(response, unprocessable)
    }

    return response
  }

  private async httpError(response: Response, unprocessable: UnprocessableMeaning): Promise<Error> {
    const status = response.status
    switch (status) {
      case 400:
        return (await this.hasType(response, AUTHORIZATION_REQUEST_REJECTED))
          ? new InvalidConsentRequestError()
          : new ConsentFailedError('The consent request failed with an unrecognised 400')
      case 422:
        return 'malformed-decision' === unprocessable
          ? new MalformedConsentDecisionError()
          : new ConsentFailedError('The consent request failed with status 422')
      case 401:
        // L'accord sur une authentification trop ancienne a son propre type ;
        // tout autre 401 (cookie absent ou expiré) est une session expirée.
        return (await this.hasType(response, REAUTHENTICATION_REQUIRED))
          ? new ReauthenticationRequiredError()
          : new SessionExpiredError()
      case 403:
        // 403 couvre aussi le refus CSRF : dans les deux cas, se reconnecter
        // repose un cookie de session et un jeton CSRF neufs.
        return new SessionExpiredError()
      default:
        return new ConsentFailedError(`The consent request failed with status ${status}`)
    }
  }

  /** Le problem+json porte-t-il ce `type` ? Un corps illisible n'en porte aucun. */
  private async hasType(response: Response, slug: string): Promise<boolean> {
    const body = (await response.json().catch(() => null)) as ApiProblemBody | null

    return null !== body && hasProblemType(body, slug)
  }

  private async readJson(response: Response): Promise<unknown> {
    try {
      return await response.json()
    } catch {
      throw new ConsentFailedError('The consent response is not JSON')
    }
  }

  private parseSummary(body: unknown): ConsentRequest {
    const summary = body as Partial<Record<keyof ConsentRequest, unknown>> | null
    const client = summary?.client as Partial<Record<string, unknown>> | null | undefined
    const account = summary?.account as Partial<Record<string, unknown>> | null | undefined

    if (
      'string' !== typeof client?.id ||
      'string' !== typeof client.name ||
      'preregistered' !== client.registration ||
      'string' !== typeof summary?.redirectHost ||
      NOMINATIVE_READ_SCOPE !== summary.scope ||
      'string' !== typeof summary.resource ||
      'string' !== typeof account?.username
    ) {
      throw new ConsentFailedError('The consent summary has an unexpected shape')
    }

    return {
      client: { id: client.id, name: client.name, registration: client.registration },
      redirectHost: summary.redirectHost,
      scope: NOMINATIVE_READ_SCOPE,
      resource: summary.resource,
      account: { username: account.username },
    }
  }
}
