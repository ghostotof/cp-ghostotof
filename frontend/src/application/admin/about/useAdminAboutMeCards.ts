import { inject, ref, type InjectionKey, type Ref } from 'vue'
import type { AdminAboutMeCard } from '../../../domain/admin/about/entities/AdminAboutMeCard'
import type {
  AdminAboutMeCardInput,
  AdminAboutMeCardRepository,
} from '../../../domain/admin/about/repositories/AdminAboutMeCardRepository'
import { AdminAboutError } from '../../../domain/admin/about/errors/AdminAboutError'
import type { Locale } from '../../../domain/portfolio/entities/Locale'

export const ADMIN_ABOUT_ME_CARD_REPOSITORY: InjectionKey<AdminAboutMeCardRepository> = Symbol('AdminAboutMeCardRepository')

export interface UseAdminAboutMeCardsResult {
  cards: Ref<readonly AdminAboutMeCard[]>
  isLoading: Ref<boolean>
  hasError: Ref<boolean>
  errorMessage: Ref<AdminAboutError | null>
  load: (locale: Locale) => Promise<void>
  create: (input: AdminAboutMeCardInput) => Promise<void>
  update: (id: number, input: AdminAboutMeCardInput) => Promise<void>
  remove: (id: number) => Promise<void>
}

/**
 * Pas de dépendance à useI18n() (cf. useAdminStats) : la page appelante
 * possède le sélecteur de locale et pilote load(locale). Contrairement aux
 * autres composables admin about, load() charge TOUJOURS les 3 catégories
 * (pas de filtre `category` passé ici) : c'est la page qui répartit les
 * cartes reçues en 3 groupes pour l'affichage/l'édition.
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
  let currentLocale: Locale | null = null

  const load = async (locale: Locale): Promise<void> => {
    currentLocale = locale
    isLoading.value = true
    hasError.value = false

    try {
      cards.value = await repository.list(locale)
    } catch {
      hasError.value = true
    } finally {
      isLoading.value = false
    }
  }

  const runMutation = async (mutation: () => Promise<unknown>): Promise<void> => {
    errorMessage.value = null

    try {
      await mutation()
      if (currentLocale) {
        await load(currentLocale)
      }
    } catch (error) {
      errorMessage.value = error instanceof AdminAboutError ? error : new AdminAboutError('unknown', 'Unknown error')
    }
  }

  const create = (input: AdminAboutMeCardInput): Promise<void> => runMutation(() => repository.create(input))

  const update = (id: number, input: AdminAboutMeCardInput): Promise<void> => runMutation(() => repository.update(id, input))

  const remove = (id: number): Promise<void> => runMutation(() => repository.remove(id))

  return { cards, isLoading, hasError, errorMessage, load, create, update, remove }
}
