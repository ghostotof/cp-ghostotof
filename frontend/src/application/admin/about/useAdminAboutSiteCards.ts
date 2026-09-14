import { inject, ref, type InjectionKey, type Ref } from 'vue'
import type { AdminAboutSiteCard } from '../../../domain/admin/about/entities/AdminAboutSiteCard'
import type {
  AdminAboutSiteCardInput,
  AdminAboutSiteCardRepository,
} from '../../../domain/admin/about/repositories/AdminAboutSiteCardRepository'
import { AdminAboutError } from '../../../domain/admin/about/errors/AdminAboutError'
import { createStaleRequestGuard } from '../../shared/staleRequestGuard'

export const ADMIN_ABOUT_SITE_CARD_REPOSITORY: InjectionKey<AdminAboutSiteCardRepository> = Symbol('AdminAboutSiteCardRepository')

export interface UseAdminAboutSiteCardsResult {
  cards: Ref<readonly AdminAboutSiteCard[]>
  isLoading: Ref<boolean>
  hasError: Ref<boolean>
  errorMessage: Ref<AdminAboutError | null>
  load: () => Promise<void>
  create: (input: AdminAboutSiteCardInput) => Promise<void>
  update: (id: string, input: AdminAboutSiteCardInput) => Promise<void>
  remove: (id: string) => Promise<void>
  reorder: (keys: readonly string[]) => Promise<void>
}

/**
 * Pas de dépendance à useI18n() (cf. useAdminExperienceTechnologies) : la page
 * appelante traduit `errorMessage.reason`, ce qui garde ce composable testable
 * sans instance i18n.
 *
 * `load()` ne prend plus de locale depuis la spec 0004 (D8) : le tableau du
 * backoffice affiche toutes les langues, la langue choisie ne pilote plus que
 * le formulaire. La liste se charge donc une fois, à la création du
 * composable, et non plus à chaque changement de langue.
 */
export function useAdminAboutSiteCards(): UseAdminAboutSiteCardsResult {
  const repository = inject(ADMIN_ABOUT_SITE_CARD_REPOSITORY)

  if (!repository) {
    throw new Error(
      "AdminAboutSiteCardRepository n'a pas été fourni. Vérifiez que app.provide(ADMIN_ABOUT_SITE_CARD_REPOSITORY, ...) est bien appelé dans main.ts.",
    )
  }

  const cards = ref<readonly AdminAboutSiteCard[]>([])
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

  const create = (input: AdminAboutSiteCardInput): Promise<void> => runMutation(() => repository.create(input))

  const update = (id: string, input: AdminAboutSiteCardInput): Promise<void> => runMutation(() => repository.update(id, input))

  const remove = (id: string): Promise<void> => runMutation(() => repository.remove(id))

  /**
   * Volontairement hors de `runMutation` : ni rechargement ni absorption de
   * l'erreur ici. `useOrderDraft` recharge lui-même après un enregistrement
   * réussi, et il a besoin de recevoir l'`AdminOrderError` telle quelle pour
   * distinguer un ordre obsolète (D4) d'une panne — la convertir en
   * `AdminAboutError` lui retirerait cette information.
   */
  const reorder = (keys: readonly string[]): Promise<void> => repository.reorder(keys)

  void load()

  return { cards, isLoading, hasError, errorMessage, load, create, update, remove, reorder }
}
