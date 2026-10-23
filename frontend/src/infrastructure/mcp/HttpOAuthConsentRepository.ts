import type { ConsentParams, ConsentRequest } from '../../domain/mcp/entities/ConsentRequest'
import type { OAuthConsentRepository } from '../../domain/mcp/repositories/OAuthConsentRepository'
import { consentQuery } from '../../domain/mcp/services/consentParams'
import { ConsentFailedError } from '../../domain/mcp/errors/ConsentFailedError'
import { InvalidConsentRequestError } from '../../domain/mcp/errors/InvalidConsentRequestError'
import { SessionExpiredError } from '../../domain/mcp/errors/SessionExpiredError'
import { readCsrfToken } from '../auth/csrfCookie'

const PATH = '/api/oauth/consent'

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
 */
export class HttpOAuthConsentRepository implements OAuthConsentRepository {
  private readonly apiBaseUrl: string

  constructor(apiBaseUrl: string) {
    this.apiBaseUrl = apiBaseUrl
  }

  async describe(params: ConsentParams): Promise<ConsentRequest> {
    const query = new URLSearchParams(consentQuery(params)).toString()
    const response = await this.send(`${this.apiBaseUrl}${PATH}?${query}`, {
      method: 'GET',
      credentials: 'include',
      headers: { Accept: 'application/json' },
    })

    return this.parseSummary(await this.readJson(response))
  }

  async decide(params: ConsentParams, approved: boolean): Promise<string> {
    const csrfToken = readCsrfToken()
    const response = await this.send(`${this.apiBaseUrl}${PATH}`, {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        ...(csrfToken ? { 'X-XSRF-TOKEN': csrfToken } : {}),
      },
      // Les paramètres reçus, tels quels (un absent part en `null`), plus la décision.
      body: JSON.stringify({ ...params, approved }),
    })

    const redirectUri = (await this.readJson(response) as { redirectUri?: unknown } | null)?.redirectUri
    if ('string' !== typeof redirectUri) {
      throw new ConsentFailedError('The consent response carries no redirect URI')
    }

    return redirectUri
  }

  /** Appel réseau ; un statut d'erreur devient l'exception du domaine qui lui correspond. */
  private async send(url: string, init: RequestInit): Promise<Response> {
    let response: Response
    try {
      response = await fetch(url, init)
    } catch {
      throw new ConsentFailedError('The consent request could not reach the server')
    }

    if (!response.ok) {
      throw this.httpError(response.status)
    }

    return response
  }

  private httpError(status: number): Error {
    switch (status) {
      case 400:
        return new InvalidConsentRequestError()
      case 401:
      case 403:
        // 403 couvre aussi le refus CSRF : dans les deux cas, se reconnecter
        // repose un cookie de session et un jeton CSRF neufs.
        return new SessionExpiredError()
      default:
        return new ConsentFailedError(`The consent request failed with status ${status}`)
    }
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

    if (
      'string' !== typeof client?.id ||
      'string' !== typeof client.name ||
      'preregistered' !== client.registration ||
      'string' !== typeof summary?.redirectHost ||
      'string' !== typeof summary.scope ||
      'string' !== typeof summary.resource
    ) {
      throw new ConsentFailedError('The consent summary has an unexpected shape')
    }

    return {
      client: { id: client.id, name: client.name, registration: client.registration },
      redirectHost: summary.redirectHost,
      scope: summary.scope,
      resource: summary.resource,
    }
  }
}
