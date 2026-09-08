import { inject, ref, type InjectionKey, type Ref } from 'vue'
import type { AdminWatchedProduct } from '../../../domain/admin/watch/entities/AdminWatchedProduct'
import type {
  AdminWatchedProductInput,
  AdminWatchedProductRepository,
} from '../../../domain/admin/watch/repositories/AdminWatchedProductRepository'
import { AdminWatchedProductError } from '../../../domain/admin/watch/errors/AdminWatchedProductError'
import { createStaleRequestGuard } from '../../shared/staleRequestGuard'

export const ADMIN_WATCHED_PRODUCT_REPOSITORY: InjectionKey<AdminWatchedProductRepository> =
  Symbol('AdminWatchedProductRepository')

export interface UseAdminWatchedProductsResult {
  products: Ref<readonly AdminWatchedProduct[]>
  isLoading: Ref<boolean>
  hasError: Ref<boolean>
  errorMessage: Ref<AdminWatchedProductError | null>
  load: () => Promise<void>
  create: (input: AdminWatchedProductInput) => Promise<void>
  update: (id: number, input: AdminWatchedProductInput) => Promise<void>
  remove: (id: number) => Promise<void>
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
  const isLoading = ref(true)
  const hasError = ref(false)
  const errorMessage = ref<AdminWatchedProductError | null>(null)
  const requestGuard = createStaleRequestGuard()

  const load = async (): Promise<void> => {
    const token = requestGuard.begin()
    isLoading.value = true
    hasError.value = false

    try {
      const result = await repository.list()
      if (!requestGuard.isCurrent(token)) return
      products.value = result
    } catch {
      if (requestGuard.isCurrent(token)) hasError.value = true
    } finally {
      if (requestGuard.isCurrent(token)) isLoading.value = false
    }
  }

  const runMutation = async (mutation: () => Promise<unknown>): Promise<void> => {
    errorMessage.value = null

    try {
      await mutation()
      await load()
    } catch (error) {
      errorMessage.value =
        error instanceof AdminWatchedProductError
          ? error
          : new AdminWatchedProductError('unknown', 'Unknown error')
    }
  }

  const create = (input: AdminWatchedProductInput): Promise<void> => runMutation(() => repository.create(input))

  const update = (id: number, input: AdminWatchedProductInput): Promise<void> =>
    runMutation(() => repository.update(id, input))

  const remove = (id: number): Promise<void> => runMutation(() => repository.remove(id))

  void load()

  return { products, isLoading, hasError, errorMessage, load, create, update, remove }
}
