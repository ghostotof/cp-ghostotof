import { inject, ref, type InjectionKey, type Ref } from 'vue'
import type { AdminAboutSiteCard } from '../../../domain/admin/about/entities/AdminAboutSiteCard'
import type {
  AdminAboutSiteCardInput,
  AdminAboutSiteCardRepository,
} from '../../../domain/admin/about/repositories/AdminAboutSiteCardRepository'
import { AdminAboutError } from '../../../domain/admin/about/errors/AdminAboutError'
import { useAdminLoad, type AdminLoadState } from '../shared/useAdminLoad'
import { visibleMutationError } from '../shared/visibleMutationError'

export const ADMIN_ABOUT_SITE_CARD_REPOSITORY: InjectionKey<AdminAboutSiteCardRepository> = Symbol('AdminAboutSiteCardRepository')

export interface UseAdminAboutSiteCardsResult extends AdminLoadState {
  cards: Ref<readonly AdminAboutSiteCard[]>
  errorMessage: Ref<AdminAboutError | null>
  load: () => Promise<void>
  create: (input: AdminAboutSiteCardInput) => Promise<boolean>
  update: (id: string, input: AdminAboutSiteCardInput) => Promise<boolean>
  remove: (id: string) => Promise<boolean>
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
  const errorMessage = ref<AdminAboutError | null>(null)

  const { isLoading, hasError, load } = useAdminLoad({
    read: () => repository.list(),
    apply: (value) => {
      cards.value = value
    },
  })

  const runMutation = async (mutation: () => Promise<unknown>): Promise<boolean> => {
    errorMessage.value = null

    try {
      await mutation()
      await load()

      return true
    } catch (error) {
      errorMessage.value = visibleMutationError(error, AdminAboutError, () => new AdminAboutError('unknown', 'Unknown error'))

      return false
    }
  }

  const create = (input: AdminAboutSiteCardInput): Promise<boolean> => runMutation(() => repository.create(input))

  const update = (id: string, input: AdminAboutSiteCardInput): Promise<boolean> => runMutation(() => repository.update(id, input))

  const remove = (id: string): Promise<boolean> => runMutation(() => repository.remove(id))

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
