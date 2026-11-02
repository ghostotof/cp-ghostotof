import { inject, ref, watch, type InjectionKey, type Ref } from 'vue'
import type { ExperienceTechnology } from '../../domain/experience/entities/ExperienceTechnology'
import type { ExperienceTechnologyRepository } from '../../domain/experience/repositories/ExperienceTechnologyRepository'
import { useCurrentLocale } from '../shared/useCurrentLocale'

export const EXPERIENCE_TECHNOLOGY_REPOSITORY: InjectionKey<ExperienceTechnologyRepository> = Symbol(
  'ExperienceTechnologyRepository',
)

export interface UseExperienceTechnologiesResult {
  technologies: Ref<readonly ExperienceTechnology[]>
  isLoading: Ref<boolean>
  hasError: Ref<boolean>
}

/**
 * Charge le classement des technologies depuis le backend. Recharge à chaque
 * changement de locale (le libellé `duration` de chaque technologie en
 * dépend) : la charge utile étant minime, un nouvel appel réseau reste plus
 * simple qu'un cache local à invalider manuellement.
 */
export function useExperienceTechnologies(): UseExperienceTechnologiesResult {
  const repository = inject(EXPERIENCE_TECHNOLOGY_REPOSITORY)

  if (!repository) {
    throw new Error(
      "ExperienceTechnologyRepository n'a pas été fourni. " +
        'Vérifiez que app.provide(EXPERIENCE_TECHNOLOGY_REPOSITORY, ...) est bien appelé dans main.ts.',
    )
  }

  const currentLocale = useCurrentLocale()
  const technologies = ref<readonly ExperienceTechnology[]>([])
  const isLoading = ref(true)
  const hasError = ref(false)

  const load = async (): Promise<void> => {
    isLoading.value = true
    hasError.value = false

    try {
      technologies.value = await repository.list(currentLocale.value)
    } catch {
      hasError.value = true
    } finally {
      isLoading.value = false
    }
  }

  watch(currentLocale, load, { immediate: true })

  return { technologies, isLoading, hasError }
}
