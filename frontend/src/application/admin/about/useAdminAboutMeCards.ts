import { inject, ref, type InjectionKey, type Ref } from 'vue'
import type { AdminAboutMeCard, AdminAboutMeCardCategory } from '../../../domain/admin/about/entities/AdminAboutMeCard'
import type {
  AdminAboutMeCardInput,
  AdminAboutMeCardRepository,
} from '../../../domain/admin/about/repositories/AdminAboutMeCardRepository'
import { AdminAboutError } from '../../../domain/admin/about/errors/AdminAboutError'
import { createStaleRequestGuard } from '../../shared/staleRequestGuard'

export const ADMIN_ABOUT_ME_CARD_REPOSITORY: InjectionKey<AdminAboutMeCardRepository> = Symbol('AdminAboutMeCardRepository')

export interface UseAdminAboutMeCardsResult {
  cards: Ref<readonly AdminAboutMeCard[]>
  isLoading: Ref<boolean>
  hasError: Ref<boolean>
  errorMessage: Ref<AdminAboutError | null>
  load: () => Promise<void>
  create: (input: AdminAboutMeCardInput) => Promise<void>
  update: (id: string, input: AdminAboutMeCardInput) => Promise<void>
  remove: (id: string) => Promise<void>
  reorder: (keys: readonly string[], category: AdminAboutMeCardCategory) => Promise<void>
}

/**
 * Pas de dépendance à useI18n() (cf. useAdminExperienceTechnologies) : la page
 * appelante traduit `errorMessage.reason`.
 *
 * `load()` ne prend ni locale ni catégorie depuis la spec 0004 (D8) : la
 * collection entière est chargée une fois, et la page en tire ses trois
 * tableaux — un par catégorie, chacun avec son propre ordre. Trois requêtes
 * filtrées auraient dit la même chose en trois allers-retours.
 */
export function useAdminAboutMeCards(): UseAdminAboutMeCardsResult {
  const repository = inject(ADMIN_ABOUT_ME_CARD_REPOSITORY)

  if (!repository) {
    throw new Error(
      "AdminAboutMeCardRepository n'a pas été fourni. Vérifiez que app.provide(ADMIN_ABOUT_ME_CARD_REPOSITORY, ...) est bien appelé dans main.ts.",
    )
  }

  const cards = ref<readonly AdminAboutMeCard[]>([])
  const isLoading = ref(true)
  const hasError = ref(false)
  const errorMessage = ref<AdminAboutError | null>(null)
  const requestGuard = createStaleRequestGuard()

  const load = async (): Promise<void> => {
    const token = requestGuard.begin()
    isLoading.value = true
    hasError.value = false

    try {
      const result = await repository.list()
      if (!requestGuard.isCurrent(token)) return
      cards.value = result
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
      errorMessage.value = error instanceof AdminAboutError ? error : new AdminAboutError('unknown', 'Unknown error')
    }
  }

  const create = (input: AdminAboutMeCardInput): Promise<void> => runMutation(() => repository.create(input))

  const update = (id: string, input: AdminAboutMeCardInput): Promise<void> => runMutation(() => repository.update(id, input))

  const remove = (id: string): Promise<void> => runMutation(() => repository.remove(id))

  /**
   * Volontairement hors de `runMutation` (cf. useAdminAboutSiteCards) : c'est
   * `useOrderDraft` qui recharge et qui a besoin de l'`AdminOrderError` intacte.
   * La catégorie accompagne les clés : elle est le périmètre de l'ordre.
   */
  const reorder = (keys: readonly string[], category: AdminAboutMeCardCategory): Promise<void> =>
    repository.reorder(keys, category)

  void load()

  return { cards, isLoading, hasError, errorMessage, load, create, update, remove, reorder }
}
