import { inject, ref, type InjectionKey, type Ref } from 'vue'
import type { AdminWatchedProduct } from '../../../domain/admin/watch/entities/AdminWatchedProduct'
import type {
  AdminWatchedProductInput,
  AdminWatchedProductRepository,
} from '../../../domain/admin/watch/repositories/AdminWatchedProductRepository'
import { AdminWatchedProductError } from '../../../domain/admin/watch/errors/AdminWatchedProductError'
import { useAdminLoad, type AdminLoadState } from '../shared/useAdminLoad'
import { visibleMutationError } from '../shared/visibleMutationError'

export const ADMIN_WATCHED_PRODUCT_REPOSITORY: InjectionKey<AdminWatchedProductRepository> =
  Symbol('AdminWatchedProductRepository')

export interface UseAdminWatchedProductsResult extends AdminLoadState {
  products: Ref<readonly AdminWatchedProduct[]>
  errorMessage: Ref<AdminWatchedProductError | null>
  load: () => Promise<void>
  create: (input: AdminWatchedProductInput) => Promise<boolean>
  update: (id: string, input: AdminWatchedProductInput) => Promise<boolean>
  remove: (id: string) => Promise<boolean>
  reorder: (ids: readonly string[]) => Promise<void>
}

/**
 * Pas de dépendance à useI18n() (cf. useAdminIncidents) : la page appelante
 * traduit `errorMessage.reason`.
 *
 * `hasError` couvre l'échec du chargement initial ; `errorMessage` celui d'une
 * mutation — distincts, pour ne pas masquer une liste chargée derrière une
 * erreur de formulaire.
 */
export function useAdminWatchedProducts(): UseAdminWatchedProductsResult {
  const repository = inject(ADMIN_WATCHED_PRODUCT_REPOSITORY)

  if (!repository) {
    throw new Error(
      "AdminWatchedProductRepository n'a pas été fourni. " +
        'Vérifiez que app.provide(ADMIN_WATCHED_PRODUCT_REPOSITORY, ...) est bien appelé dans main.ts.',
    )
  }

  const products = ref<readonly AdminWatchedProduct[]>([])
  const errorMessage = ref<AdminWatchedProductError | null>(null)

  const { isLoading, hasError, isLoaded, load } = useAdminLoad({
    read: () => repository.list(),
    apply: (value) => {
      products.value = value
    },
  })

  const runMutation = async (mutation: () => Promise<unknown>): Promise<boolean> => {
    errorMessage.value = null

    try {
      await mutation()
      await load()

      return true
    } catch (error) {
      errorMessage.value = visibleMutationError(error, AdminWatchedProductError, () => new AdminWatchedProductError('unknown', 'Unknown error'))

      return false
    }
  }

  const create = (input: AdminWatchedProductInput): Promise<boolean> => runMutation(() => repository.create(input))

  const update = (id: string, input: AdminWatchedProductInput): Promise<boolean> =>
    runMutation(() => repository.update(id, input))

  const remove = (id: string): Promise<boolean> => runMutation(() => repository.remove(id))

  /**
   * Volontairement hors de `runMutation` : ni rechargement ni absorption de
   * l'erreur ici. `useOrderDraft` recharge lui-même après un enregistrement
   * réussi, et il a besoin de recevoir l'`AdminOrderError` telle quelle pour
   * distinguer un ordre obsolète (D4) d'une panne.
   */
  const reorder = (ids: readonly string[]): Promise<void> => repository.reorder(ids)

  void load()

  return { products, isLoading, hasError, isLoaded, errorMessage, load, create, update, remove, reorder }
}
