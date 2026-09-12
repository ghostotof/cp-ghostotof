import { inject, ref, type InjectionKey, type Ref } from 'vue'
import type { BaseAccessRepository } from '../../domain/baseAccess/repositories/BaseAccessRepository'
import { BaseAccessError, type BaseAccessErrorReason } from '../../domain/baseAccess/errors/BaseAccessError'

export const BASE_ACCESS_REPOSITORY: InjectionKey<BaseAccessRepository> = Symbol('BaseAccessRepository')

export interface UseBaseAccessResult {
  isGranting: Ref<boolean>
  errorReason: Ref<BaseAccessErrorReason | null>
  /** @returns true si l'accès a été obtenu (l'appelant peut relire le contenu protégé). */
  grant: () => Promise<boolean>
}

/**
 * Action « obtenir le palier de base » (ADR 0003 D6, POST
 * /api/account/base-access). Pas de rechargement automatique : c'est
 * l'appelant (ex. CaseStudiesPage) qui décide de relire le contenu protégé
 * une fois grant() résolu à true.
 */
export function useBaseAccess(): UseBaseAccessResult {
  const repository = inject(BASE_ACCESS_REPOSITORY)

  if (!repository) {
    throw new Error(
      "BaseAccessRepository n'a pas été fourni. " +
        'Vérifiez que app.provide(BASE_ACCESS_REPOSITORY, ...) est bien appelé dans main.ts.',
    )
  }

  const isGranting = ref(false)
  const errorReason = ref<BaseAccessErrorReason | null>(null)

  const grant = async (): Promise<boolean> => {
    isGranting.value = true
    errorReason.value = null

    try {
      await repository.grant()
      return true
    } catch (error) {
      errorReason.value = error instanceof BaseAccessError ? error.reason : 'unknown'
      return false
    } finally {
      isGranting.value = false
    }
  }

  return { isGranting, errorReason, grant }
}
