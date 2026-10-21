/**
 * Ce qu'un client MCP attend pour se brancher sur le serveur du site : la
 * commande Claude Code, le fragment JSON `mcpServers` (Cursor, PhpStorm), ou
 * l'URL seule (les clients qui la font saisir dans un formulaire : claude.ai,
 * Claude Desktop, ChatGPT). `clientConfiguration()` en construit le texte.
 */
export type McpClientConfigurationKind = 'claude-code-command' | 'mcp-servers-json' | 'server-url'
