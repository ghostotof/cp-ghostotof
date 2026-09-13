import { inject, ref, watch, type InjectionKey, type Ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { AnonymousCvSection } from '../../domain/anonymousCv/entities/AnonymousCvSection'
import type { AnonymousCvRepository } from '../../domain/anonymousCv/repositories/AnonymousCvRepository'
import type { Locale } from '../../domain/portfolio/entities/Locale'
import { AnonymousCvAccessNotGrantedError } from '../../domain/anonymousCv/errors/AnonymousCvAccessNotGrantedError'
import { createStaleRequestGuard } from '../shared/staleRequestGuard'
import { markBaseAccessExpired } from '../auth/useAuth'

export const ANONYMOUS_CV_REPOSITORY: InjectionKey<AnonymousCvRepository> = Symbol('AnonymousCvRepository')

export interface UseAnonymousCvResult {
  sections: Ref<readonly AnonymousCvSection[]>
  isLoading: Ref<boolean>
  hasError: Ref<boolean>
  /** Vrai sur un 401/403 (ADR 0003 D6) : distinct de hasError, la présentation
   * propose l'action qui débloque l'accès plutôt qu'un message générique. */
  needsAccess: Ref<boolean>
  /** Relance la récupération (ex. après avoir obtenu le palier de base). */
  reload: () => Promise<void>
}

/**
 * Charge le CV sans identité depuis le backend, et recharge à chaque
 * changement de locale — même forme que useCaseStudies.
 */
export function useAnonymousCv(): UseAnonymousCvResult {
  const repository = inject(ANONYMOUS_CV_REPOSITORY)

  if (!repository) {
    throw new Error(
      "AnonymousCvRepository n'a pas été fourni. " +
        'Vérifiez que app.provide(ANONYMOUS_CV_REPOSITORY, ...) est bien appelé dans main.ts.',
    )
  }

  const { locale } = useI18n()
  const sections = ref<readonly AnonymousCvSection[]>([])
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
      sections.value = loaded
    } catch (error) {
      if (!requestGuard.isCurrent(token)) return
      if (error instanceof AnonymousCvAccessNotGrantedError) {
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

  return { sections, isLoading, hasError, needsAccess, reload: load }
}
