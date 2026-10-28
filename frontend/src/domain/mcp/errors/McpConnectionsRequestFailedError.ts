/**
 * Tout autre échec de la liste ou de la révocation : réseau coupé, 5xx, limite
 * de débit, 404 d'un autre `type` que `mcp-connection-not-found`, réponse 200
 * qui n'a pas la forme attendue. La page n'a qu'un message, « réessayez ».
 */
export class McpConnectionsRequestFailedError extends Error {
  constructor(message = 'The MCP connections request failed') {
    super(message)
    this.name = 'McpConnectionsRequestFailedError'
  }
}
