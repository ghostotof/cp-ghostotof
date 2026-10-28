import type { McpConnectionList, McpConnectionSummary } from '../../src/domain/mcp/entities/McpConnection'

/** Ce que `McpConnectionsRepository.list()` rend : les connexions, et si l'historique des terminées est coupé. */
export function listOf(connections: McpConnectionSummary[], terminatedTruncated = false): McpConnectionList {
  return { connections, terminatedTruncated }
}
