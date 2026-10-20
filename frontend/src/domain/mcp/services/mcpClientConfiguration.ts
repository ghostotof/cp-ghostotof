/**
 * Ce qu'une personne colle dans son client MCP pour brancher son assistant sur
 * le serveur MCP du site (spec 0007, page « Brancher votre assistant »).
 *
 * Fonctions pures, sans dépendance au navigateur : l'origine est reçue en
 * paramètre (la page la lit dans `window.location`), ce qui les rend testables
 * sans jsdom et garde le domaine indépendant de la présentation.
 */
import type { McpClientConfigurationKind } from '../entities/McpClientConfigurationKind'

/**
 * Chemin du serveur MCP (`/api/mcp`, ADR 0006) : sous `/api`, parce que
 * l'Ingress n'envoie que ce préfixe au backend — `/mcp` servirait l'`index.html`
 * du SPA.
 */
export const MCP_ENDPOINT_PATH = '/api/mcp'

/**
 * Nom sous lequel la personne enregistre le serveur dans son client : celui que
 * le serveur déclare (`serverInfo.name`, exception D8 de l'ADR 0006), qui reprend
 * la marque déjà affichée sur tout le site. Un nom local, que le client
 * n'envoie jamais au serveur.
 */
export const MCP_SERVER_LOCAL_NAME = 'cp-ghostotof'

/**
 * URL du serveur MCP, construite depuis l'origine de la page plutôt que depuis
 * l'URL de l'API (`getApiUrl()`) : le serveur n'accepte que l'hôte public du
 * site (`allowed_hosts`, dérivé d'`APP_FRONTEND_BASE_URL`), c'est-à-dire celui
 * qui sert cette page. `new URL` plutôt qu'une concaténation : le port est
 * conservé et aucune double barre ne peut apparaître.
 */
export function mcpServerUrl(origin: string): string {
  return new URL(MCP_ENDPOINT_PATH, origin).href
}

/** Ligne de commande Claude Code : un serveur distant, transport Streamable HTTP. */
export function claudeCodeCommand(serverUrl: string): string {
  return `claude mcp add --transport http ${MCP_SERVER_LOCAL_NAME} ${serverUrl}`
}

/**
 * Fragment `mcpServers` lu par Cursor (`mcp.json`) et par l'AI Assistant de
 * PhpStorm (ajout d'un serveur « As JSON ») : un serveur distant s'y décrit par
 * son seul `url`. Sérialisé par `JSON.stringify`, jamais écrit à la main : il
 * reste du JSON valide quelle que soit l'URL.
 */
export function mcpServersJson(serverUrl: string): string {
  return JSON.stringify({ mcpServers: { [MCP_SERVER_LOCAL_NAME]: { url: serverUrl } } }, null, 2)
}

/**
 * Une entrée par type : un type ajouté sans entrée ici est une erreur de
 * compilation, pas un bloc vide en production.
 */
const CONFIGURATION_BUILDERS: Record<McpClientConfigurationKind, (serverUrl: string) => string> = {
  'claude-code-command': claudeCodeCommand,
  'mcp-servers-json': mcpServersJson,
  'server-url': (serverUrl) => serverUrl,
}

/** Le texte à copier dans un client, pour le type de configuration qu'il attend. */
export function clientConfiguration(kind: McpClientConfigurationKind, serverUrl: string): string {
  return CONFIGURATION_BUILDERS[kind](serverUrl)
}
