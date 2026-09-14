import type { AdminWatchedProduct } from '../entities/AdminWatchedProduct'

/**
 * Corps d'écriture d'un produit surveillé. Sans `position` (spec 0004, D3 :
 * elle n'est plus jamais saisie — une entrée neuve se range en fin de
 * catalogue, seul `reorder()` déplace) et sans `translationGroup` : ce
 * contexte n'est pas localisé, un numéro de version est un fait, pas une
 * traduction.
 */
export interface AdminWatchedProductInput {
  slug: string
  label: string
  versionSource: string
  /** Nulle pour une source runtime : la version est lue dans le processus. */
  version: string | null
}

/**
 * Abstraction (DIP) dont dépend l'application. Distincte de WatchRepository
 * (lecture publique seule) : celle-ci couvre le CRUD réservé au backoffice.
 */
export interface AdminWatchedProductRepository {
  list(): Promise<readonly AdminWatchedProduct[]>

  create(input: AdminWatchedProductInput): Promise<AdminWatchedProduct>

  update(id: string, input: AdminWatchedProductInput): Promise<AdminWatchedProduct>

  remove(id: string): Promise<void>

  /**
   * `PUT …/order` : la liste **complète** des ids du catalogue, dans l'ordre
   * voulu (spec 0004, D4 — un sous-ensemble est refusé). La clé est l'id, pas
   * un groupe de traduction : ce contexte n'en a pas. Rejette avec
   * `AdminOrderError`, jamais avec `AdminWatchedProductError` : c'est
   * `useOrderDraft` qui traite l'échec, et il ne connaît que ce type-là.
   */
  reorder(ids: readonly string[]): Promise<void>
}
