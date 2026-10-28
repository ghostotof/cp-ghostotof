/**
 * Le serveur ne connaît pas cette connexion pour ce compte (404
 * `mcp-connection-not-found`) : elle a été purgée, ou n'a jamais été à elle.
 * Pour la page, c'est « déjà terminée » : la liste est rechargée, sans alerte.
 */
export class McpConnectionNotFoundError extends Error {
  constructor(message = 'The MCP connection is unknown for this account') {
    super(message)
    this.name = 'McpConnectionNotFoundError'
  }
}
