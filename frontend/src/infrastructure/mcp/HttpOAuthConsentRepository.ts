import { NOMINATIVE_READ_SCOPE, type ConsentParams, type ConsentRequest } from '../../domain/mcp/entities/ConsentRequest'
import type { OAuthConsentRepository } from '../../domain/mcp/repositories/OAuthConsentRepository'
import { consentQuery } from '../../domain/mcp/services/consentParams'
import { ConsentAccountChangedError } from '../../domain/mcp/errors/ConsentAccountChangedError'
import { ConsentFailedError } from '../../domain/mcp/errors/ConsentFailedError'
import { ConsentForbiddenError } from '../../domain/mcp/errors/ConsentForbiddenError'
import { InvalidConsentRequestError } from '../../domain/mcp/errors/InvalidConsentRequestError'
import { MalformedConsentDecisionError } from '../../domain/mcp/errors/MalformedConsentDecisionError'
import { ReauthenticationRequiredError } from '../../domain/mcp/errors/ReauthenticationRequiredError'
import { SessionExpiredError } from '../../domain/mcp/errors/SessionExpiredError'
import { readCsrfToken } from '../auth/csrfCookie'
import { endsTheSession } from '../http/sessionEnded'
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

/**
 * Le slug du 409 que le serveur rend quand la décision a été prise sur la page
 * d'un autre compte que celui du cookie (`ConsentAccountChangedException`,
 * #650, F5). Reconnu par le `type` ET le statut, jamais par le `detail`
 * localisé : tout autre 409 reste un échec générique.
 */
export const CONSENT_ACCOUNT_CHANGED = 'consent-account-changed'

/** Ce que veut dire un 422 : sur la décision, un corps que la page a mal formé. */
type UnprocessableMeaning = 'malformed-decision' | 'generic'

/**
 * Implémentation HTTP d'OAuthConsentRepository : `GET` puis `POST
 * /api/oauth/consent` (#647). Cookie httpOnly BEARER (`credentials:
 * 'include'`) sur les deux, double-submit CSRF (`X-XSRF-TOKEN`) sur le `POST`,
 * comme toute mutation de l'API.
 *
 * Un 401, ou un 403 au `POST` parti sans jeton CSRF (cookies expirés par la
 * déconnexion), est une session finie (`SessionExpiredError`, règle
 * `endsTheSession`) : se reconnecter repose des cookies neufs. Tout autre 403 — le
 * `GET`, que le CSRF ne contrôle jamais, ou le `POST` qui portait son jeton — est un
 * refus réel (compte sans l'accès de confiance, signature CSRF périmée) :
 * `ConsentForbiddenError`, que la page ne présente pas comme une session finie
 * (#748, #732 point 4).
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
 * Le compte qui accorde (#650) est exigé aussi, UUID compris : le serveur le lit
 * sur le cookie de la requête, la page ne le déduit de rien, et la décision le
 * lui renvoie (F5).
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
      // Une lecture n'est jamais refusée pour le CSRF : son 403 est un refus réel.
      false,
    )

    return this.parseSummary(await this.readJson(response))
  }

  async decide(params: ConsentParams, approved: boolean, accountId: string): Promise<string> {
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
        // Les paramètres reçus, tels quels (un absent part en `null`), la décision,
        // et l'UUID du compte que le résumé a nommé (F5) : le serveur refuse en 409
        // si le cookie est devenu celui d'un autre.
        body: JSON.stringify({ ...params, approved, accountId }),
      },
      'malformed-decision',
      // Pas de jeton à envoyer (cookie absent ou vide) : le client n'a pas posé l'en-tête,
      // et un 403 du serveur est alors le refus CSRF d'une session finie.
      !csrfToken,
    )

    const redirectUri = (await this.readJson(response) as { redirectUri?: unknown } | null)?.redirectUri
    if ('string' !== typeof redirectUri) {
      throw new ConsentFailedError('The consent response carries no redirect URI')
    }

    return redirectUri
  }

  /**
   * Appel réseau ; un statut d'erreur devient l'exception du domaine qui lui correspond.
   * `sentWithoutCsrfToken` : vrai pour une écriture partie sans en-tête `X-XSRF-TOKEN`,
   * connu à l'envoi (voir `endsTheSession`).
   */
  private async send(
    url: string,
    init: RequestInit,
    unprocessable: UnprocessableMeaning,
    sentWithoutCsrfToken: boolean,
  ): Promise<Response> {
    let response: Response
    try {
      response = await fetch(url, init)
    } catch {
      throw new ConsentFailedError('The consent request could not reach the server')
    }

    if (!response.ok) {
      throw await this.httpError(response, unprocessable, sentWithoutCsrfToken)
    }

    return response
  }

  private async httpError(response: Response, unprocessable: UnprocessableMeaning, sentWithoutCsrfToken: boolean): Promise<Error> {
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
      case 409:
        // Le compte connecté n'est plus celui que la page a montré ; tout autre
        // 409 est un échec générique.
        return (await this.hasType(response, CONSENT_ACCOUNT_CHANGED))
          ? new ConsentAccountChangedError()
          : new ConsentFailedError('The consent request failed with an unrecognised 409')
      case 403:
        // Un 403 à une décision partie sans jeton CSRF est une session finie (se
        // reconnecter repose des cookies neufs) ; tout autre est un refus réel, que se
        // reconnecter au même compte ne lèverait pas (#748, #732 point 4).
        return endsTheSession(403, sentWithoutCsrfToken) ? new SessionExpiredError() : new ConsentForbiddenError()
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
      'string' !== typeof account?.id ||
      'string' !== typeof account.username
    ) {
      throw new ConsentFailedError('The consent summary has an unexpected shape')
    }

    return {
      client: { id: client.id, name: client.name, registration: client.registration },
      redirectHost: summary.redirectHost,
      scope: NOMINATIVE_READ_SCOPE,
      resource: summary.resource,
      account: { id: account.id, username: account.username },
    }
  }
}
