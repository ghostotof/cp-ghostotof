import { inject, ref, watch, type InjectionKey, type Ref } from 'vue'
import type { Contribution } from '../../domain/contributions/entities/Contribution'
import type { ContributionRepository } from '../../domain/contributions/repositories/ContributionRepository'
import { useCurrentLocale } from '../shared/useCurrentLocale'
import { createStaleRequestGuard } from '../shared/staleRequestGuard'

export const CONTRIBUTION_REPOSITORY: InjectionKey<ContributionRepository> = Symbol('ContributionRepository')

export interface UseContributionsResult {
  contributions: Ref<readonly Contribution[]>
  isLoading: Ref<boolean>
  hasError: Ref<boolean>
}

/**
 * Charge les contributions depuis le backend, et recharge à chaque changement
 * de locale.
 */
export function useContributions(): UseContributionsResult {
  const repository = inject(CONTRIBUTION_REPOSITORY)

  if (!repository) {
    throw new Error(
      "ContributionRepository n'a pas été fourni. " +
        'Vérifiez que app.provide(CONTRIBUTION_REPOSITORY, ...) est bien appelé dans main.ts.',
    )
  }

  const currentLocale = useCurrentLocale()
  const contributions = ref<readonly Contribution[]>([])
  const isLoading = ref(true)
  const hasError = ref(false)
  const requestGuard = createStaleRequestGuard()

  const load = async (): Promise<void> => {
    const token = requestGuard.begin()
    isLoading.value = true
    hasError.value = false

    try {
      const loaded = await repository.list(currentLocale.value)
      if (!requestGuard.isCurrent(token)) return
      contributions.value = loaded
    } catch {
      if (requestGuard.isCurrent(token)) hasError.value = true
    } finally {
      if (requestGuard.isCurrent(token)) isLoading.value = false
    }
  }

  watch(currentLocale, load, { immediate: true })

  return { contributions, isLoading, hasError }
}
