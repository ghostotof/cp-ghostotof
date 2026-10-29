import type { McpConnectionList } from '../entities/McpConnection'

/**
 * Abstraction (DIP) de « Mes connexions MCP » (#650, spec 0007 tâche 11).
 * L'implémentation HTTP (HttpMcpConnectionsRepository) est injectée au
 * composition root (main.ts). Réservé au palier de confiance : session par
 * cookie, CSRF sur la révocation.
 */
export interface McpConnectionsRepository {
  /**
   * Les connexions du compte, dans l'ordre du serveur (octroi décroissant) :
   * toutes celles qui ne sont pas révoquées (actives, ou échues que la purge
   * n'a pas encore marquées) et les dernières terminées (révoquées, expirées
   * marquées), le serveur bornant ces dernières. `terminatedTruncated` dit
   * qu'il en existe de plus anciennes, que la liste ne porte pas.
   *
   * @throws SessionEndedError session absente ou expirée (401, voir `infrastructure/http/sessionAwareFetch.ts`)
   * @throws AccessForbiddenError tout autre 403 : un refus réel (compte sans l'accès de confiance, ou qui l'a perdu)
   * @throws McpConnectionsRequestFailedError tout autre échec, dont une réponse mal formée
   */
  list(): Promise<McpConnectionList>

  /**
   * Révoque l'une de ses connexions. Idempotent côté serveur : une connexion
   * déjà révoquée répond comme une révocation réussie.
   *
   * @throws McpConnectionNotFoundError connexion inconnue de ce compte (404 `mcp-connection-not-found`)
   * @throws SessionEndedError session absente ou expirée (401, ou 403 parti sans jeton CSRF, voir `infrastructure/http/sessionAwareFetch.ts`)
   * @throws AccessForbiddenError tout autre 403 : un refus réel
   * @throws McpConnectionsRequestFailedError tout autre échec
   */
  revoke(id: string): Promise<void>
}
