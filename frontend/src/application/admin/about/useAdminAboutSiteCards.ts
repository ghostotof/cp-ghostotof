import { inject, ref, type InjectionKey, type Ref } from 'vue'
import type { AdminAboutSiteCard } from '../../../domain/admin/about/entities/AdminAboutSiteCard'
import type {
  AdminAboutSiteCardInput,
  AdminAboutSiteCardRepository,
} from '../../../domain/admin/about/repositories/AdminAboutSiteCardRepository'
import { AdminAboutError } from '../../../domain/admin/about/errors/AdminAboutError'
import type { Locale } from '../../../domain/portfolio/entities/Locale'
import { createStaleRequestGuard } from '../../shared/staleRequestGuard'

export const ADMIN_ABOUT_SITE_CARD_REPOSITORY: InjectionKey<AdminAboutSiteCardRepository> = Symbol('AdminAboutSiteCardRepository')

export interface UseAdminAboutSiteCardsResult {
  cards: Ref<readonly AdminAboutSiteCard[]>
  isLoading: Ref<boolean>
  hasError: Ref<boolean>
  errorMessage: Ref<AdminAboutError | null>
  load: (locale: Locale) => Promise<void>
  create: (input: AdminAboutSiteCardInput) => Promise<void>
  update: (id: number, input: AdminAboutSiteCardInput) => Promise<void>
  remove: (id: number) => Promise<void>
}

/**
 * Pas de dépendance à useI18n() (cf. useAdminStats) : la page appelante
 * possède le sélecteur de locale et pilote load(locale).
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
  let currentLocale: Locale | null = null

  const load = async (locale: Locale): Promise<void> => {
    currentLocale = locale
    const token = requestGuard.begin()
    isLoading.value = true
    hasError.value = false

    try {
      const result = await repository.list(locale)
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
      if (currentLocale) {
        await load(currentLocale)
      }
    } catch (error) {
      errorMessage.value = error instanceof AdminAboutError ? error : new AdminAboutError('unknown', 'Unknown error')
    }
  }

  const create = (input: AdminAboutSiteCardInput): Promise<void> => runMutation(() => repository.create(input))

  const update = (id: number, input: AdminAboutSiteCardInput): Promise<void> => runMutation(() => repository.update(id, input))

  const remove = (id: number): Promise<void> => runMutation(() => repository.remove(id))

  return { cards, isLoading, hasError, errorMessage, load, create, update, remove }
}
