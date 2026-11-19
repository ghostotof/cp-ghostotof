import {
  NOMINATIVE_READ_SCOPE,
  type ConsentClient,
  type ConsentParams,
  type ConsentRequest,
} from '../../domain/mcp/entities/ConsentRequest'
import type { OAuthConsentRepository } from '../../domain/mcp/repositories/OAuthConsentRepository'
import { consentQuery } from '../../domain/mcp/services/consentParams'
import { AccessForbiddenError } from '../../domain/auth/errors/AccessForbiddenError'
import { SessionEndedError } from '../../domain/auth/errors/SessionEndedError'
import { ConsentAccountChangedError } from '../../domain/mcp/errors/ConsentAccountChangedError'
import { ConsentFailedError } from '../../domain/mcp/errors/ConsentFailedError'
import { ConsentTemporarilyUnavailableError } from '../../domain/mcp/errors/ConsentTemporarilyUnavailableError'
import { InvalidConsentRequestError } from '../../domain/mcp/errors/InvalidConsentRequestError'
import { MalformedConsentDecisionError } from '../../domain/mcp/errors/MalformedConsentDecisionError'
import { ReauthenticationRequiredError } from '../../domain/mcp/errors/ReauthenticationRequiredError'
import { hasProblemType, type ApiProblemBody } from '../http/problemJson'
import { retryAfterSeconds } from '../http/retryAfterSeconds'
import { sessionAwareFetch, type SessionAwareInit, type SessionAwareResponse } from '../http/sessionAwareFetch'

const PATH = '/api/oauth/consent'

/**
 * Le mode d'enregistrement du client et son domaine vérifié, lus ensemble : le
 * domaine est une chaîne non vide si et seulement si le client est `cimd`
 * (#651, contrat de la Task 10). Tout autre couple — un `cimd` sans domaine, un
 * `preregistered` qui en porte un, un mode inconnu — est refusé : la page ne
 * montre jamais « vérifié » à côté d'une information que le serveur n'a pas
 * donnée pour telle, ni ne cache un domaine qu'elle devrait montrer.
 */
function parseRegistration(
  registration: unknown,
  domain: unknown,
): Pick<ConsentClient, 'registration' | 'domain'> | null {
  if ('cimd' === registration && 'string' === typeof domain && '' !== domain) {
    return { registration, domain }
  }

  if ('preregistered' === registration && null === domain) {
    return { registration, domain }
  }

  return null
}

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

/**
 * Le slug du refus passager (`ConsentTemporarilyUnavailableException`, #651) : un 429 (quota de
 * revalidation atteint, `Retry-After` en secondes) ou un 503 (hôte du client injoignable, sans délai
 * garanti), au `GET` comme au `POST`. Le `detail` est constant : seul le `type` décide, et un 429 ou un
 * 503 d'un autre type reste un échec générique.
 */
export const CONSENT_TEMPORARILY_UNAVAILABLE = 'consent-temporarily-unavailable'

/**
 * Le slug du 429 que nginx rend quand la zone de limitation du consentement est atteinte
 * (`docker/nginx/default.conf`, `"type":"/errors/rate-limited"`) : le backend n'a pas jugé la
 * demande, c'est le même « réessayez ». Reconnu au 429 seulement, au `GET` comme au `POST`.
 */
export const RATE_LIMITED = 'rate-limited'

/** Ce que veut dire un 422 : sur la décision, un corps que la page a mal formé. */
type UnprocessableMeaning = 'malformed-decision' | 'generic'

/**
 * Implémentation HTTP d'OAuthConsentRepository : `GET` puis `POST
 * /api/oauth/consent` (#647). L'envoi (cookies, CSRF du `POST`) et le verdict
 * « session finie » sont ceux de `infrastructure/http/sessionAwareFetch.ts`, qui porte
 * la règle : un 401 sans type, ou un 403 au `POST` parti sans jeton, est une
 * `SessionEndedError` ; tout autre 403 (le `GET`, ou le `POST` qui portait son jeton)
 * est un refus réel, `AccessForbiddenError` (#748, #732 point 4).
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
      { method: 'GET', headers: { Accept: 'application/json' } },
      // Un GET n'a pas de corps : un 422 n'y a pas de sens propre.
      'generic',
    )

    return this.parseSummary(await this.readJson(response))
  }

  async decide(params: ConsentParams, approved: boolean, accountId: string): Promise<string> {
    const response = await this.send(
      `${this.apiBaseUrl}${PATH}`,
      {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        // Les paramètres reçus, tels quels (un absent part en `null`), la décision,
        // et l'UUID du compte que le résumé a nommé (F5) : le serveur refuse en 409
        // si le cookie est devenu celui d'un autre.
        body: JSON.stringify({ ...params, approved, accountId }),
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
  private async send(url: string, init: SessionAwareInit, unprocessable: UnprocessableMeaning): Promise<Response> {
    let judged: SessionAwareResponse
    try {
      judged = await sessionAwareFetch(url, init)
    } catch {
      throw new ConsentFailedError('The consent request could not reach the server')
    }

    if (!judged.response.ok) {
      throw await this.httpError(judged, unprocessable)
    }

    return judged.response
  }

  private async httpError(judged: SessionAwareResponse, unprocessable: UnprocessableMeaning): Promise<Error> {
    const response = judged.response
    const status = response.status

    // Le seul cas typé avant le verdict : l'accord sur une authentification trop ancienne a son
    // propre type (un 401 que la session ne finit pas) ; tout autre 401 est une session finie.
    if (401 === status && (await this.hasType(response, REAUTHENTICATION_REQUIRED))) {
      return new ReauthenticationRequiredError()
    }

    // Puis le verdict de la règle commune ; un autre 403 est un refus réel, que se reconnecter
    // au même compte ne lèverait pas (#748, #732 point 4).
    if (judged.endedTheSession) {
      return new SessionEndedError()
    }

    switch (status) {
      case 400:
        return (await this.hasType(response, AUTHORIZATION_REQUEST_REJECTED))
          ? new InvalidConsentRequestError()
          : new ConsentFailedError('The consent request failed with an unrecognised 400')
      case 422:
        return 'malformed-decision' === unprocessable
          ? new MalformedConsentDecisionError()
          : new ConsentFailedError('The consent request failed with status 422')
      case 409:
        // Le compte connecté n'est plus celui que la page a montré ; tout autre
        // 409 est un échec générique.
        return (await this.hasType(response, CONSENT_ACCOUNT_CHANGED))
          ? new ConsentAccountChangedError()
          : new ConsentFailedError('The consent request failed with an unrecognised 409')
      case 403:
        return new AccessForbiddenError()
      case 429:
      case 503:
        return this.temporaryRefusal(response)
      default:
        return new ConsentFailedError(`The consent request failed with status ${status}`)
    }
  }

  /**
   * Un 429 ou un 503 : « réessayez » (la demande n'a pas été jugée) ou échec générique.
   *
   * Le corps ne se lit qu'une fois, d'où une lecture unique pour les deux slugs :
   * - `consent-temporarily-unavailable`, rendu par le backend au 429 comme au 503 ;
   * - `rate-limited`, rendu par nginx au seul 429 (`location @rate_limited`), sans `Retry-After` : la page
   *   dit alors « réessayez » sans délai ; un `Retry-After` qu'un intermédiaire y ajouterait serait lu.
   * Le délai n'est lu que sur un 429 : le backend ne pose `Retry-After` que là, et un 503 qui en
   * porterait un (proxy, répartiteur) n'engage pas la durée d'une indisponibilité de l'hôte du client.
   * Tout autre type, ou un corps illisible, reste un échec générique.
   */
  private async temporaryRefusal(response: Response): Promise<Error> {
    const status = response.status
    const body = (await response.json().catch(() => null)) as ApiProblemBody | null
    const temporary =
      null !== body &&
      (hasProblemType(body, CONSENT_TEMPORARILY_UNAVAILABLE) || (429 === status && hasProblemType(body, RATE_LIMITED)))

    return temporary
      ? new ConsentTemporarilyUnavailableError(429 === status ? retryAfterSeconds(response) : null)
      : new ConsentFailedError(`The consent request failed with status ${status}`)
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
    const registration = parseRegistration(client?.registration, client?.domain)

    if (
      'string' !== typeof client?.id ||
      'string' !== typeof client.name ||
      null === registration ||
      'string' !== typeof summary?.redirectHost ||
      NOMINATIVE_READ_SCOPE !== summary.scope ||
      'string' !== typeof summary.resource ||
      'string' !== typeof account?.id ||
      'string' !== typeof account.username
    ) {
      throw new ConsentFailedError('The consent summary has an unexpected shape')
    }

    return {
      client: { id: client.id, name: client.name, ...registration },
      redirectHost: summary.redirectHost,
      scope: NOMINATIVE_READ_SCOPE,
      resource: summary.resource,
      account: { id: account.id, username: account.username },
    }
  }
}
