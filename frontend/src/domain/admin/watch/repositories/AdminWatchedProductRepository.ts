import type { AdminWatchedProduct } from '../entities/AdminWatchedProduct'

export interface AdminWatchedProductInput {
  slug: string
  label: string
  versionSource: string
  /** Nulle pour une source runtime : la version est lue dans le processus. */
  version: string | null
  position: number
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
}
