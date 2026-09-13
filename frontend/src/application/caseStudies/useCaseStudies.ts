import { inject, ref, watch, type InjectionKey, type Ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { CaseStudy } from '../../domain/caseStudies/entities/CaseStudy'
import type { CaseStudyRepository } from '../../domain/caseStudies/repositories/CaseStudyRepository'
import type { Locale } from '../../domain/portfolio/entities/Locale'
import { CaseStudiesAccessNotGrantedError } from '../../domain/caseStudies/errors/CaseStudiesAccessNotGrantedError'
import { createStaleRequestGuard } from '../shared/staleRequestGuard'
import { markBaseAccessExpired } from '../auth/useAuth'

export const CASE_STUDY_REPOSITORY: InjectionKey<CaseStudyRepository> = Symbol('CaseStudyRepository')

export interface UseCaseStudiesResult {
  caseStudies: Ref<readonly CaseStudy[]>
  isLoading: Ref<boolean>
  hasError: Ref<boolean>
  /** Vrai sur un 403 (ADR 0003 D6) : distinct de hasError, la présentation
   * propose l'action qui débloque l'accès plutôt qu'un message générique. */
  needsAccess: Ref<boolean>
  /** Relance la récupération (ex. après avoir obtenu le palier de base). */
  reload: () => Promise<void>
}

/**
 * Charge les études de cas depuis le backend, et recharge à chaque
 * changement de locale — même forme que useContributions, avec un état
 * supplémentaire (needsAccess) puisque cette source exige le palier de base.
 */
export function useCaseStudies(): UseCaseStudiesResult {
  const repository = inject(CASE_STUDY_REPOSITORY)

  if (!repository) {
    throw new Error(
      "CaseStudyRepository n'a pas été fourni. " +
        'Vérifiez que app.provide(CASE_STUDY_REPOSITORY, ...) est bien appelé dans main.ts.',
    )
  }

  const { locale } = useI18n()
  const caseStudies = ref<readonly CaseStudy[]>([])
  const isLoading = ref(true)
  const hasError = ref(false)
  const needsAccess = ref(false)
  const requestGuard = createStaleRequestGuard()

  const load = async (): Promise<void> => {
    const token = requestGuard.begin()
    isLoading.value = true
    hasError.value = false
    needsAccess.value = false

    try {
      const loaded = await repository.list(locale.value as Locale)
      if (!requestGuard.isCurrent(token)) return
      caseStudies.value = loaded
    } catch (error) {
      if (!requestGuard.isCurrent(token)) return
      if (error instanceof CaseStudiesAccessNotGrantedError) {
        needsAccess.value = true
        // Le serveur vient de dire que le jeton ne vaut plus rien : l'en-tête
        // ne doit pas continuer d'afficher « Accès de base » au-dessus d'une
        // page qui demande de l'obtenir.
        markBaseAccessExpired()
      } else {
        hasError.value = true
      }
    } finally {
      if (requestGuard.isCurrent(token)) isLoading.value = false
    }
  }

  watch(locale, load, { immediate: true })

  return { caseStudies, isLoading, hasError, needsAccess, reload: load }
}
