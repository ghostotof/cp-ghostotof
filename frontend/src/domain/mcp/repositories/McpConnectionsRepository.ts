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
   * @throws SessionExpiredError session absente, expirée ou sans le palier de confiance (401/403)
   * @throws McpConnectionsRequestFailedError tout autre échec, dont une réponse mal formée
   */
  list(): Promise<McpConnectionList>

  /**
   * Révoque l'une de ses connexions. Idempotent côté serveur : une connexion
   * déjà révoquée répond comme une révocation réussie.
   *
   * @throws McpConnectionNotFoundError connexion inconnue de ce compte (404 `mcp-connection-not-found`)
   * @throws SessionExpiredError session absente, expirée, sans le palier de confiance, ou CSRF refusé (401/403)
   * @throws McpConnectionsRequestFailedError tout autre échec
   */
  revoke(id: string): Promise<void>
}
