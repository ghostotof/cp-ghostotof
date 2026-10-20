/**
 * Contenu de la page « Brancher votre assistant » (spec 0007, phase 1) : comment
 * ajouter le serveur MCP du site à un client MCP, ce qu'il sert par palier et ce
 * qu'il ne sert jamais. Contenu statique par langue : il décrit le serveur, il
 * n'est pas éditable au backoffice.
 *
 * Les configurations à copier n'y figurent pas en texte : elles portent l'URL du
 * serveur, connue à l'exécution seulement (l'origine de la page). Le contenu dit
 * quelle configuration montrer (`McpClientConfigurationKind`), la page la
 * construit (`domain/mcp/services/mcpClientConfiguration`).
 */

/**
 * Ce que la page affiche à copier pour un client : la commande Claude Code, le
 * fragment JSON `mcpServers` (Cursor, PhpStorm), ou l'URL seule (les clients qui
 * la font saisir dans un formulaire).
 */
export type McpClientConfigurationKind = 'claude-code-command' | 'mcp-servers-json' | 'server-url'

export interface McpClientConfiguration {
  readonly kind: McpClientConfigurationKind
  /** Ce que copie le bouton, complément de « Copier » lu par les lecteurs d'écran. */
  readonly copyLabel: string
}

export interface McpClientGuide {
  /** Identifiant stable (ancre, sélecteur de test), indépendant de la langue. */
  readonly id: string
  readonly name: string
  readonly steps: readonly string[]
  readonly configuration: McpClientConfiguration
  /** Précision facultative sous la configuration (limite connue du client…). */
  readonly note?: string
}

/** Un contenu servi : le nom de l'outil MCP (non traduit) et ce qu'il rend. */
export interface McpServedTool {
  readonly tool: string
  readonly description: string
}

export interface McpTier {
  /** Palier de l'ADR 0003 dont l'outil sert les routes (`anonymous`, `base`). */
  readonly id: 'anonymous' | 'base'
  readonly heading: string
  readonly description: string
  readonly tools: readonly McpServedTool[]
}

export interface ConnectAssistantContent {
  readonly eyebrow: string
  readonly title: string
  readonly introduction: readonly string[]
  readonly serverUrl: {
    readonly heading: string
    readonly description: string
    readonly copyLabel: string
  }
  readonly served: {
    readonly heading: string
    readonly description: string
    readonly tiers: readonly McpTier[]
    readonly prompt: McpServedTool & { readonly heading: string }
  }
  readonly neverServed: {
    readonly heading: string
    readonly items: readonly string[]
  }
  readonly privacy: {
    readonly text: string
    readonly linkLabel: string
  }
  readonly clients: {
    readonly heading: string
    readonly description: string
    readonly guides: readonly McpClientGuide[]
  }
}
