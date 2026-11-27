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
 *
 * La traduction d'un statut en raison est la même pour toutes les méthodes
 * (404 → `not-found` ; 409 → `declared-by-document` si son type est
 * `client-declared-by-document`, sinon `client-id-taken` ; 422 →
 * `id-of-verified-domain` si son type est `client-id-of-verified-domain`,
 * sinon `validation` ; tout autre échec, corps refusé (400) et réponse mal
 * formée compris → `unknown`). Les docblocks ci-dessous disent ce que chaque
 * route peut rendre.
 */
export interface AdminMcpRepository {
  /** Les clients, dans l'ordre que le serveur rend. Aucun refus attendu : tout échec est `unknown`. */
  listClients(): Promise<readonly AdminMcpClient[]>

  /**
   * Enregistre un client pré-enregistré ; rend le client créé (201). Refus :
   * - 409 sans type → `client-id-taken` : l'identifiant est déjà pris, y compris
   *   par un enregistrement concurrent ;
   * - 422 avec `violations` → `validation` : un champ refusé (identifiant,
   *   nom, une redirection, leur nombre), identifiant d'un domaine vérifié
   *   compris, refusé d'ordinaire au champ `clientId` ;
   * - 422 de type `client-id-of-verified-domain` → `id-of-verified-domain` :
   *   le même refus quand il échappe au validateur
   *   (`cannotBePreregistered()`), voir `AdminMcpError`.
   */
  registerClient(input: AdminMcpClientInput): Promise<AdminMcpClient>

  /**
   * Remplace le nom et les redirections d'un client pré-enregistré ; rend le
   * client révisé (200). Refus :
   * - 404 → `not-found` : le client a été supprimé depuis la lecture ;
   * - 409 de type `client-declared-by-document` → `declared-by-document` : un
   *   client CIMD, que seul son document de métadonnées fait évoluer ;
   * - 422 de type `client-id-of-verified-domain` → `id-of-verified-domain` :
   *   l'identifiant est celui d'un domaine vérifié, le client se supprime ;
   * - 422 avec `violations` → `validation` : le nom, une redirection ou leur
   *   nombre refusés.
   */
  reviseClient(id: string, revision: AdminMcpClientRevision): Promise<AdminMcpClient>

  /**
   * Supprime le client et coupe ses connexions (204) ; un client CIMD se
   * supprime aussi. Refus : 404 → `not-found`, le client a déjà été supprimé.
   */
  removeClient(id: string): Promise<void>

  /**
   * Les connexions de tous les comptes — toutes les vivantes, plus les
   * dernières terminées —, dans l'ordre que le serveur rend : la page ne
   * retrie pas. Aucun refus attendu : tout échec est `unknown`.
   */
  listConnections(): Promise<readonly AdminMcpConnection[]>
}
