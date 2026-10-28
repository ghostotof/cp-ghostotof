import { MCP_CONNECTION_STATES, type McpConnectionList, type McpConnectionState, type McpConnectionSummary } from '../../domain/mcp/entities/McpConnection'
import type { McpConnectionsRepository } from '../../domain/mcp/repositories/McpConnectionsRepository'
import { McpConnectionNotFoundError } from '../../domain/mcp/errors/McpConnectionNotFoundError'
import { McpConnectionsRequestFailedError } from '../../domain/mcp/errors/McpConnectionsRequestFailedError'
import { SessionExpiredError } from '../../domain/mcp/errors/SessionExpiredError'
import { readCsrfToken } from '../auth/csrfCookie'
import type { ApiProblemBody } from '../admin/shared/BackofficeHttpClient'
import { hasProblemType } from '../admin/shared/orderProblems'

const PATH = '/api/me/mcp-connections'

/**
 * Le slug du 404 que le serveur rend pour une connexion inconnue ou d'un autre
 * compte (`McpConnectionNotFoundException`). Reconnu par le `type` du
 * problem+json, jamais par le `detail` localisé : un 404 du routeur ou d'un
 * proxy n'est pas « déjà terminée ». Renommé côté backend, il se renomme ici
 * dans le même changement.
 */
export const MCP_CONNECTION_NOT_FOUND = 'mcp-connection-not-found'

/**
 * Implémentation HTTP de McpConnectionsRepository : `GET` puis `DELETE
 * /api/me/mcp-connections[/{id}]` (#650). Cookie httpOnly BEARER (`credentials:
 * 'include'`) sur les deux, double-submit CSRF (`X-XSRF-TOKEN`) sur le `DELETE`,
 * comme toute mutation de l'API.
 *
 * Le corps de la liste est vérifié champ par champ : une réponse d'une autre
 * forme (version du backend désynchronisée, proxy qui répond du HTML) est un
 * échec, plutôt qu'une page qui afficherait `undefined` ou « Invalid Date ».
 * Seule la cause de révocation reste une chaîne libre (voir McpConnectionSummary).
 * `terminatedTruncated` (historique coupé par le serveur) est un booléen exigé,
 * comme `codeExchanged` ; `expiresAt` est `null` exactement pour une connexion
 * révoquée (#650, R5a) — l'un sans l'autre est une réponse refusée.
 */
export class HttpMcpConnectionsRepository implements McpConnectionsRepository {
  private readonly apiBaseUrl: string

  constructor(apiBaseUrl: string) {
    this.apiBaseUrl = apiBaseUrl
  }

  async list(): Promise<McpConnectionList> {
    const response = await this.send(`${this.apiBaseUrl}${PATH}`, {
      method: 'GET',
      credentials: 'include',
      headers: { Accept: 'application/json' },
    })

    let body: unknown
    try {
      body = await response.json()
    } catch {
      throw new McpConnectionsRequestFailedError('The MCP connections response is not JSON')
    }

    return this.parseList(body)
  }

  async revoke(id: string): Promise<void> {
    const csrfToken = readCsrfToken()
    await this.send(`${this.apiBaseUrl}${PATH}/${encodeURIComponent(id)}`, {
      method: 'DELETE',
      credentials: 'include',
      headers: {
        Accept: 'application/json',
        ...(csrfToken ? { 'X-XSRF-TOKEN': csrfToken } : {}),
      },
    })
  }

  /** Appel réseau ; un statut d'erreur devient l'exception du domaine qui lui correspond. */
  private async send(url: string, init: RequestInit): Promise<Response> {
    let response: Response
    try {
      response = await fetch(url, init)
    } catch {
      throw new McpConnectionsRequestFailedError('The MCP connections request could not reach the server')
    }

    if (!response.ok) {
      throw await this.httpError(response)
    }

    return response
  }

  private async httpError(response: Response): Promise<Error> {
    const status = response.status
    switch (status) {
      case 401:
      case 403:
        // 403 couvre aussi le refus CSRF : dans les deux cas, se reconnecter
        // repose un cookie de session et un jeton CSRF neufs.
        return new SessionExpiredError()
      case 404:
        return (await this.hasType(response, MCP_CONNECTION_NOT_FOUND))
          ? new McpConnectionNotFoundError()
          : new McpConnectionsRequestFailedError('The MCP connections request failed with an unrecognised 404')
      default:
        return new McpConnectionsRequestFailedError(`The MCP connections request failed with status ${status}`)
    }
  }

  /** Le problem+json porte-t-il ce `type` ? Un corps illisible n'en porte aucun. */
  private async hasType(response: Response, slug: string): Promise<boolean> {
    const body = (await response.json().catch(() => null)) as ApiProblemBody | null

    return null !== body && 'object' === typeof body && hasProblemType(body, slug)
  }

  private parseList(body: unknown): McpConnectionList {
    const raw = body as { connections?: unknown; terminatedTruncated?: unknown } | null
    // `terminatedTruncated` est lu strictement, comme le reste : un booléen,
    // jamais « truthy ». Absent (un backend plus ancien), la page ne saurait
    // pas dire si l'historique est coupé : mieux vaut un échec qu'un silence.
    if (!Array.isArray(raw?.connections) || 'boolean' !== typeof raw.terminatedTruncated) {
      throw new McpConnectionsRequestFailedError('The MCP connections response has an unexpected shape')
    }

    return {
      connections: raw.connections.map((entry) => this.parseEntry(entry)),
      terminatedTruncated: raw.terminatedTruncated,
    }
  }

  private parseEntry(entry: unknown): McpConnectionSummary {
    const raw = entry as Partial<Record<string, unknown>> | null
    const client = raw?.client as Partial<Record<string, unknown>> | null | undefined

    if (
      'string' !== typeof raw?.id ||
      'string' !== typeof client?.id ||
      'string' !== typeof client.name ||
      'string' !== typeof raw.grantedAt ||
      'boolean' !== typeof raw.codeExchanged ||
      !this.isStringOrNull(raw.expiresAt) ||
      !this.isStringOrNull(raw.lastUsedAt) ||
      !this.isStringOrNull(raw.revokedAt) ||
      !this.isStringOrNull(raw.revocationCause) ||
      !this.isState(raw.state)
    ) {
      throw new McpConnectionsRequestFailedError('An MCP connection has an unexpected shape')
    }

    // L'échéance est absente exactement pour une connexion révoquée : l'une sans
    // l'autre est un serveur et une page qui ne parlent plus la même langue.
    if (('revoked' === raw.state) !== (null === raw.expiresAt)) {
      throw new McpConnectionsRequestFailedError('An MCP connection has an expiry inconsistent with its state')
    }

    return {
      id: raw.id,
      client: { id: client.id, name: client.name },
      grantedAt: raw.grantedAt,
      codeExchanged: raw.codeExchanged,
      lastUsedAt: raw.lastUsedAt,
      expiresAt: raw.expiresAt,
      state: raw.state,
      revokedAt: raw.revokedAt,
      revocationCause: raw.revocationCause,
    }
  }

  private isStringOrNull(value: unknown): value is string | null {
    return null === value || 'string' === typeof value
  }

  private isState(value: unknown): value is McpConnectionState {
    return MCP_CONNECTION_STATES.some((state) => state === value)
  }
}
