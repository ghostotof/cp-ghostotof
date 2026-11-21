import type { AdminMcpClient } from '../entities/AdminMcpClient'
import type { AdminMcpConnection } from '../entities/AdminMcpConnection'

/**
 * Corps de `POST /api/backoffice/mcp/clients`, reflet **exact** de
 * `BackofficeMcpClientInput` : le serveur refuse tout attribut en trop (400),
 * `registration` compris — un client enregistré ici est toujours `preregistered`.
 * L'implémentation construit le corps champ par champ, jamais par décomposition
 * (`...`) d'un objet plus large : TypeScript ne refuse pas une clé en trop
 * hors d'un littéral.
 */
export interface AdminMcpClientInput {
  readonly clientId: string
  readonly name: string
  readonly redirectUris: readonly string[]
}

/**
 * Corps de `PUT /api/backoffice/mcp/clients/{id}`, reflet **exact** de
 * `BackofficeMcpClientRevisionInput` : sans `clientId`, l'identifiant public
 * d'un client ne se révise pas (le serveur refuse la clé, 400). Même règle de
 * construction que `AdminMcpClientInput`.
 */
export interface AdminMcpClientRevision {
  readonly name: string
  readonly redirectUris: readonly string[]
}

/**
 * Abstraction (DIP) dont dépend l'application. L'implémentation concrète
 * (HttpAdminMcpRepository) est injectée au niveau du composition root
 * (main.ts), jamais instanciée directement par un composant. Chaque échec
 * rejette avec `AdminMcpError` (une session terminée ou un refus restent ceux
 * de `BackofficeHttpClient`).
 */
export interface AdminMcpRepository {
  listClients(): Promise<readonly AdminMcpClient[]>

  /** Enregistre un client pré-enregistré ; rend le client créé (201). */
  registerClient(input: AdminMcpClientInput): Promise<AdminMcpClient>

  /** Remplace le nom et les redirections d'un client pré-enregistré ; rend le client révisé (200). */
  reviseClient(id: string, revision: AdminMcpClientRevision): Promise<AdminMcpClient>

  /** Supprime le client et coupe ses connexions (204). */
  removeClient(id: string): Promise<void>

  /**
   * Les connexions de tous les comptes — toutes les vivantes, plus les
   * dernières terminées —, dans l'ordre que le serveur rend : la page ne
   * retrie pas.
   */
  listConnections(): Promise<readonly AdminMcpConnection[]>
}
