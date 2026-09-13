import { inject, ref, type InjectionKey, type Ref } from 'vue'
import type { BaseAccessRepository } from '../../domain/baseAccess/repositories/BaseAccessRepository'
import { BaseAccessError, type BaseAccessErrorReason } from '../../domain/baseAccess/errors/BaseAccessError'
import { markBaseAccessGranted } from '../auth/useAuth'

export const BASE_ACCESS_REPOSITORY: InjectionKey<BaseAccessRepository> = Symbol('BaseAccessRepository')

export interface UseBaseAccessResult {
  isGranting: Ref<boolean>
  errorReason: Ref<BaseAccessErrorReason | null>
  /** @returns true si l'accès a été obtenu (l'appelant peut relire le contenu protégé). */
  grant: () => Promise<boolean>
}

/**
 * Action « obtenir le palier de base » (ADR 0003 D6, POST
 * /api/account/base-access). En cas de succès, l'état d'auth partagé passe
 * au palier de base (markBaseAccessGranted) : c'est ce que l'en-tête et les
 * pages observent — CaseStudiesPage relit son contenu sur ce changement,
 * quel que soit l'endroit d'où l'accès a été obtenu (son propre bouton ou
 * le CTA de l'en-tête).
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
      const { expiresAt } = await repository.grant()
      markBaseAccessGranted(expiresAt)
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
