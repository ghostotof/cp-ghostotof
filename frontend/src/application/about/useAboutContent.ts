import { inject, ref, watch, type InjectionKey, type Ref } from 'vue'
import type { AboutContent } from '../../domain/portfolio/entities/AboutContent'
import type { AboutContentRepository } from '../../domain/about/repositories/AboutContentRepository'
import { useCurrentLocale } from '../shared/useCurrentLocale'
import { createStaleRequestGuard } from '../shared/staleRequestGuard'

export const ABOUT_CONTENT_REPOSITORY: InjectionKey<AboutContentRepository> = Symbol('AboutContentRepository')

export interface UseAboutContentResult {
  aboutContent: Ref<AboutContent | null>
  isLoading: Ref<boolean>
  hasError: Ref<boolean>
}

/**
 * Charge le contenu de la page À propos depuis le backend. Recharge à chaque
 * changement de locale.
 */
export function useAboutContent(): UseAboutContentResult {
  const repository = inject(ABOUT_CONTENT_REPOSITORY)

  if (!repository) {
    throw new Error(
      "AboutContentRepository n'a pas été fourni. " +
        'Vérifiez que app.provide(ABOUT_CONTENT_REPOSITORY, ...) est bien appelé dans main.ts.',
    )
  }

  const currentLocale = useCurrentLocale()
  const aboutContent = ref<AboutContent | null>(null)
  const isLoading = ref(true)
  const hasError = ref(false)
  const requestGuard = createStaleRequestGuard()

  const load = async (): Promise<void> => {
    const token = requestGuard.begin()
    isLoading.value = true
    hasError.value = false

    try {
      const content = await repository.get(currentLocale.value)
      if (!requestGuard.isCurrent(token)) return
      aboutContent.value = content
    } catch {
      if (requestGuard.isCurrent(token)) hasError.value = true
    } finally {
      if (requestGuard.isCurrent(token)) isLoading.value = false
    }
  }

  watch(currentLocale, load, { immediate: true })

  return { aboutContent, isLoading, hasError }
}
