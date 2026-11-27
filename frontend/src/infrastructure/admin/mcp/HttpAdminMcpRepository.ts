import {
  ADMIN_MCP_CLIENT_REGISTRATIONS,
  type AdminMcpClient,
  type AdminMcpClientRegistration,
} from '../../../domain/admin/mcp/entities/AdminMcpClient'
import {
  ADMIN_MCP_CONNECTION_STATES,
  type AdminMcpConnection,
  type AdminMcpConnectionState,
} from '../../../domain/admin/mcp/entities/AdminMcpConnection'
import { AdminMcpError } from '../../../domain/admin/mcp/errors/AdminMcpError'
import type {
  AdminMcpClientInput,
  AdminMcpClientRevision,
  AdminMcpRepository,
} from '../../../domain/admin/mcp/repositories/AdminMcpRepository'
import { hasProblemType } from '../../http/problemJson'
import { BackofficeHttpClient, violationsMessage } from '../shared/BackofficeHttpClient'

const CLIENTS_PATH = '/api/backoffice/mcp/clients'
const CONNECTIONS_PATH = '/api/backoffice/mcp/connections'

/**
 * Les deux types de problème que le serveur pose sur les refus propres au
 * backoffice MCP (`/errors/<slug>`). Reconnus par le `type`, jamais par le
 * `detail` localisé ; renommés côté backend, ils se renomment ici dans le même
 * changement, sinon le refus retombe sur la raison générique de son statut.
 */
const CLIENT_DECLARED_BY_DOCUMENT = 'client-declared-by-document'
const CLIENT_ID_OF_VERIFIED_DOMAIN = 'client-id-of-verified-domain'

/** Message des réponses que le frontend ne comprend pas : un serveur et une page qui ne parlent plus la même langue. */
const UNEXPECTED_SHAPE = 'The MCP backoffice response has an unexpected shape'

/**
 * Implémentation HTTP d'AdminMcpRepository (#652, spec 0007 T13), sur
 * `BackofficeHttpClient` : cookie httpOnly `BEARER`, en-tête CSRF sur les
 * écritures, et la session finie (`SessionEndedError`) ou le refus réel
 * (`AccessForbiddenError`) levés par le client, qui traversent ce dépôt sans
 * être traduits (`.claude/rules/frontend-backoffice.md`).
 *
 * Chaque réponse est vérifiée champ par champ : un mode d'enregistrement ou un
 * état que le frontend ne connaît pas, un champ absent ou d'un autre type,
 * fait échouer l'opération en `AdminMcpError('unknown')` — jamais un `as` sur
 * un champ fermé. Une ligne fautive fait échouer toute la liste (limite
 * acceptée, la même que pour les autres contenus du backoffice).
 */
export class HttpAdminMcpRepository implements AdminMcpRepository {
  private readonly client: BackofficeHttpClient

  constructor(apiBaseUrl: string) {
    this.client = new BackofficeHttpClient(apiBaseUrl)
  }

  async listClients(): Promise<readonly AdminMcpClient[]> {
    const body = await this.readJson(await this.checked(this.client.get(CLIENTS_PATH)))

    return this.listOf(body, toClient)
  }

  async registerClient(input: AdminMcpClientInput): Promise<AdminMcpClient> {
    // Corps construit champ par champ, jamais par décomposition : une clé en
    // trop de l'objet reçu partirait, et le serveur la refuse (400).
    const body = { clientId: input.clientId, name: input.name, redirectUris: input.redirectUris }
    const response = await this.checked(this.client.mutate('POST', CLIENTS_PATH, body))

    return toClient(await this.readJson(response))
  }

  async reviseClient(id: string, revision: AdminMcpClientRevision): Promise<AdminMcpClient> {
    // Sans `clientId` : l'identifiant public ne se révise pas (le serveur refuse la clé, 400).
    const body = { name: revision.name, redirectUris: revision.redirectUris }
    const response = await this.checked(this.client.mutate('PUT', clientPath(id), body))

    return toClient(await this.readJson(response))
  }

  /** 204 sans corps : rien à lire. */
  async removeClient(id: string): Promise<void> {
    await this.checked(this.client.mutate('DELETE', clientPath(id)))
  }

  async listConnections(): Promise<readonly AdminMcpConnection[]> {
    const body = await this.readJson(await this.checked(this.client.get(CONNECTIONS_PATH)))

    return this.listOf(body, toConnection)
  }

  /** Attend la réponse ; un statut d'échec devient l'`AdminMcpError` qui lui correspond. */
  private async checked(sent: Promise<Response>): Promise<Response> {
    const response = await sent

    if (!response.ok) {
      throw await this.toError(response)
    }

    return response
  }

  /**
   * Le sens d'un statut ne dépend pas de l'opération : chaque refus que le
   * serveur type l'est par son slug, quelle que soit la méthode (R5).
   * - 404 : client inconnu, supprimé depuis la lecture ;
   * - 409 : `client-declared-by-document` (révision d'un client CIMD), sinon
   *   identifiant déjà pris (le seul autre 409, à l'enregistrement, sans type) ;
   * - 422 : `client-id-of-verified-domain` (sans `violations`), sinon saisie
   *   refusée champ par champ ;
   * - tout le reste (400 d'un corps refusé, 5xx…) : `unknown`.
   */
  private async toError(response: Response): Promise<AdminMcpError> {
    const problem = await this.client.parseProblem(response)
    const detail = problem.detail ?? ''

    switch (response.status) {
      case 404:
        return new AdminMcpError('not-found', detail || 'MCP client not found')
      case 409:
        return hasProblemType(problem, CLIENT_DECLARED_BY_DOCUMENT)
          ? new AdminMcpError('declared-by-document', detail || 'MCP client declared by its metadata document')
          : new AdminMcpError('client-id-taken', detail || 'MCP client id already taken')
      case 422:
        return hasProblemType(problem, CLIENT_ID_OF_VERIFIED_DOMAIN)
          ? new AdminMcpError('id-of-verified-domain', detail || 'MCP client id of a verified domain')
          : new AdminMcpError('validation', violationsMessage(problem))
      default:
        return new AdminMcpError('unknown', `Request failed with status ${response.status}`)
    }
  }

  /** Un corps illisible (un proxy qui répond du HTML sous un 200) est une réponse incomprise. */
  private async readJson(response: Response): Promise<unknown> {
    try {
      const body: unknown = await response.json()

      return body
    } catch {
      throw new AdminMcpError('unknown', 'The MCP backoffice response is not JSON')
    }
  }

  /** Une liste, chaque élément lu par `toEntry` ; tout autre corps est refusé. */
  private listOf<T>(body: unknown, toEntry: (entry: unknown) => T): readonly T[] {
    if (!Array.isArray(body)) {
      throw new AdminMcpError('unknown', UNEXPECTED_SHAPE)
    }

    return body.map(toEntry)
  }
}

/** Chemin d'un client ; l'`id` est un UUID rendu par le serveur, encodé quand même (défense en profondeur). */
function clientPath(id: string): string {
  return `${CLIENTS_PATH}/${encodeURIComponent(id)}`
}

/**
 * Lit une ligne de `BackofficeMcpClientResource`. Le drapeau
 * `shadowsVerifiedDomain` est exigé booléen, sans contrôle croisé avec le mode
 * d'enregistrement (R6) : le serveur le calcule, le frontend ne le juge pas.
 */
function toClient(entry: unknown): AdminMcpClient {
  if (
    !isRecord(entry) ||
    'string' !== typeof entry.id ||
    'string' !== typeof entry.clientId ||
    'string' !== typeof entry.name ||
    !isStringList(entry.redirectUris) ||
    !isRegistration(entry.registration) ||
    'string' !== typeof entry.registeredAt ||
    !isCount(entry.activeConnections) ||
    'boolean' !== typeof entry.shadowsVerifiedDomain
  ) {
    throw new AdminMcpError('unknown', UNEXPECTED_SHAPE)
  }

  return {
    id: entry.id,
    clientId: entry.clientId,
    name: entry.name,
    redirectUris: entry.redirectUris,
    registration: entry.registration,
    registeredAt: entry.registeredAt,
    activeConnections: entry.activeConnections,
    shadowsVerifiedDomain: entry.shadowsVerifiedDomain,
  }
}

/**
 * Lit une ligne de `BackofficeMcpConnectionResource`. `expiresAt` est exigée
 * chaîne, révoquée comprise : le backoffice la rend toujours (au contraire de
 * « Mes connexions MCP »). `revocationCause` reste une chaîne libre (voir
 * AdminMcpConnection).
 */
function toConnection(entry: unknown): AdminMcpConnection {
  if (
    !isRecord(entry) ||
    'string' !== typeof entry.id ||
    'string' !== typeof entry.account ||
    'string' !== typeof entry.clientId ||
    'string' !== typeof entry.clientName ||
    !isRegistration(entry.clientRegistration) ||
    'string' !== typeof entry.grantedAt ||
    'boolean' !== typeof entry.codeExchanged ||
    !isStringOrNull(entry.lastUsedAt) ||
    'string' !== typeof entry.expiresAt ||
    !isConnectionState(entry.state) ||
    !isStringOrNull(entry.revokedAt) ||
    !isStringOrNull(entry.revocationCause)
  ) {
    throw new AdminMcpError('unknown', UNEXPECTED_SHAPE)
  }

  return {
    id: entry.id,
    account: entry.account,
    clientId: entry.clientId,
    clientName: entry.clientName,
    clientRegistration: entry.clientRegistration,
    grantedAt: entry.grantedAt,
    codeExchanged: entry.codeExchanged,
    lastUsedAt: entry.lastUsedAt,
    expiresAt: entry.expiresAt,
    state: entry.state,
    revokedAt: entry.revokedAt,
    revocationCause: entry.revocationCause,
  }
}

/** Garde du mode d'enregistrement, sur la liste fermée du domaine : une valeur inconnue n'est jamais convertie. */
function isRegistration(value: unknown): value is AdminMcpClientRegistration {
  return ADMIN_MCP_CLIENT_REGISTRATIONS.some((registration) => registration === value)
}

/** Garde de l'état d'une connexion, sur la liste fermée du domaine. */
function isConnectionState(value: unknown): value is AdminMcpConnectionState {
  return ADMIN_MCP_CONNECTION_STATES.some((state) => state === value)
}

/** Un objet JSON (ni `null`, ni une liste), dont les champs restent à vérifier un par un. */
function isRecord(value: unknown): value is Record<string, unknown> {
  return 'object' === typeof value && null !== value && !Array.isArray(value)
}

function isStringList(value: unknown): value is string[] {
  return Array.isArray(value) && value.every((item) => 'string' === typeof item)
}

function isStringOrNull(value: unknown): value is string | null {
  return null === value || 'string' === typeof value
}

/** Un compte : entier positif ou nul. */
function isCount(value: unknown): value is number {
  return 'number' === typeof value && Number.isInteger(value) && value >= 0
}
