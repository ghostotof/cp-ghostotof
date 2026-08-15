import { inject, ref, watch, type InjectionKey, type Ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { QualityPrinciple } from '../../domain/portfolio/entities/QualityPrinciple'
import type { QualityTrait } from '../../domain/portfolio/entities/QualityTrait'
import type { QualityContentRepository } from '../../domain/quality/repositories/QualityContentRepository'
import type { Locale } from '../../domain/portfolio/entities/Locale'
import { createStaleRequestGuard } from '../shared/staleRequestGuard'

export const QUALITY_CONTENT_REPOSITORY: InjectionKey<QualityContentRepository> = Symbol('QualityContentRepository')

export interface UseQualityContentResult {
  principles: Ref<readonly QualityPrinciple[]>
  traits: Ref<readonly QualityTrait[]>
  isLoading: Ref<boolean>
  hasError: Ref<boolean>
}

/**
 * Charge les principes et traits de qualité depuis le backend. Recharge à
 * chaque changement de locale. Expose `principles`/`traits` séparément
 * (plutôt qu'un objet composite) pour rester au plus près de l'usage actuel
 * de LandingPage.vue.
 */
export function useQualityContent(): UseQualityContentResult {
  const repository = inject(QUALITY_CONTENT_REPOSITORY)

  if (!repository) {
    throw new Error(
      "QualityContentRepository n'a pas été fourni. " +
        'Vérifiez que app.provide(QUALITY_CONTENT_REPOSITORY, ...) est bien appelé dans main.ts.',
    )
  }

  const { locale } = useI18n()
  const principles = ref<readonly QualityPrinciple[]>([])
  const traits = ref<readonly QualityTrait[]>([])
  const isLoading = ref(true)
  const hasError = ref(false)
  const requestGuard = createStaleRequestGuard()

  const load = async (): Promise<void> => {
    const token = requestGuard.begin()
    isLoading.value = true
    hasError.value = false

    try {
      const content = await repository.get(locale.value as Locale)
      if (!requestGuard.isCurrent(token)) return
      principles.value = content.principles
      traits.value = content.traits
    } catch {
      if (requestGuard.isCurrent(token)) hasError.value = true
    } finally {
      if (requestGuard.isCurrent(token)) isLoading.value = false
    }
  }

  watch(locale, load, { immediate: true })

  return { principles, traits, isLoading, hasError }
}
