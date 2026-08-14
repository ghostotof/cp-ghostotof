import { inject, ref, type InjectionKey, type Ref } from 'vue'
import type { AdminStat } from '../../../domain/admin/stats/entities/AdminStat'
import type { AdminStatInput, AdminStatsRepository } from '../../../domain/admin/stats/repositories/AdminStatsRepository'
import { AdminStatsError } from '../../../domain/admin/stats/errors/AdminStatsError'
import type { Locale } from '../../../domain/portfolio/entities/Locale'

export const ADMIN_STATS_REPOSITORY: InjectionKey<AdminStatsRepository> = Symbol('AdminStatsRepository')

export interface UseAdminStatsResult {
  stats: Ref<readonly AdminStat[]>
  isLoading: Ref<boolean>
  hasError: Ref<boolean>
  errorMessage: Ref<AdminStatsError | null>
  load: (locale: Locale) => Promise<void>
  create: (input: AdminStatInput) => Promise<void>
  update: (id: number, input: AdminStatInput) => Promise<void>
  remove: (id: number) => Promise<void>
}

/**
 * Contrairement aux composables "affichage public" (ex. useStats), celui-ci
 * ne dépend pas de useI18n() : le backoffice doit permettre d'éditer
 * n'importe quelle locale indépendamment de la langue d'affichage courante de
 * l'interface. C'est la page appelante qui possède le sélecteur de locale et
 * appelle load(locale) (typiquement via un `watch` sur ce sélecteur).
 */
export function useAdminStats(): UseAdminStatsResult {
  const repository = inject(ADMIN_STATS_REPOSITORY)

  if (!repository) {
    throw new Error("AdminStatsRepository n'a pas été fourni. Vérifiez que app.provide(ADMIN_STATS_REPOSITORY, ...) est bien appelé dans main.ts.")
  }

  const stats = ref<readonly AdminStat[]>([])
  const isLoading = ref(true)
  const hasError = ref(false)
  const errorMessage = ref<AdminStatsError | null>(null)
  let currentLocale: Locale | null = null

  const load = async (locale: Locale): Promise<void> => {
    currentLocale = locale
    isLoading.value = true
    hasError.value = false

    try {
      stats.value = await repository.list(locale)
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
      errorMessage.value = error instanceof AdminStatsError ? error : new AdminStatsError('unknown', 'Unknown error')
    }
  }

  const create = (input: AdminStatInput): Promise<void> => runMutation(() => repository.create(input))

  const update = (id: number, input: AdminStatInput): Promise<void> => runMutation(() => repository.update(id, input))

  const remove = (id: number): Promise<void> => runMutation(() => repository.remove(id))

  return { stats, isLoading, hasError, errorMessage, load, create, update, remove }
}
