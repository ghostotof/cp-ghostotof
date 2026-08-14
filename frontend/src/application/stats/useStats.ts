import { inject, ref, watch, type InjectionKey, type Ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Stat } from '../../domain/portfolio/entities/Stat'
import type { StatsRepository } from '../../domain/stats/repositories/StatsRepository'
import type { Locale } from '../../domain/portfolio/entities/Locale'

export const STATS_REPOSITORY: InjectionKey<StatsRepository> = Symbol('StatsRepository')

export interface UseStatsResult {
  stats: Ref<readonly Stat[]>
  isLoading: Ref<boolean>
  hasError: Ref<boolean>
}

/**
 * Charge les statistiques clés depuis le backend. Recharge à chaque
 * changement de locale.
 */
export function useStats(): UseStatsResult {
  const repository = inject(STATS_REPOSITORY)

  if (!repository) {
    throw new Error(
      "StatsRepository n'a pas été fourni. Vérifiez que app.provide(STATS_REPOSITORY, ...) est bien appelé dans main.ts.",
    )
  }

  const { locale } = useI18n()
  const stats = ref<readonly Stat[]>([])
  const isLoading = ref(true)
  const hasError = ref(false)

  const load = async (): Promise<void> => {
    isLoading.value = true
    hasError.value = false

    try {
      stats.value = await repository.list(locale.value as Locale)
    } catch {
      hasError.value = true
    } finally {
      isLoading.value = false
    }
  }

  watch(locale, load, { immediate: true })

  return { stats, isLoading, hasError }
}
