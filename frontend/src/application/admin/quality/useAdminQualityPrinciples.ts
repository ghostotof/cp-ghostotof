import { inject, ref, type InjectionKey, type Ref } from 'vue'
import type { AdminQualityPrinciple } from '../../../domain/admin/quality/entities/AdminQualityPrinciple'
import type {
  AdminQualityPrincipleInput,
  AdminQualityPrincipleRepository,
} from '../../../domain/admin/quality/repositories/AdminQualityPrincipleRepository'
import { AdminQualityError } from '../../../domain/admin/quality/errors/AdminQualityError'
import { createStaleRequestGuard } from '../../shared/staleRequestGuard'

export const ADMIN_QUALITY_PRINCIPLE_REPOSITORY: InjectionKey<AdminQualityPrincipleRepository> = Symbol('AdminQualityPrincipleRepository')

export interface UseAdminQualityPrinciplesResult {
  principles: Ref<readonly AdminQualityPrinciple[]>
  isLoading: Ref<boolean>
  hasError: Ref<boolean>
  errorMessage: Ref<AdminQualityError | null>
  load: () => Promise<void>
  create: (input: AdminQualityPrincipleInput) => Promise<void>
  update: (id: string, input: AdminQualityPrincipleInput) => Promise<void>
  remove: (id: string) => Promise<void>
  reorder: (keys: readonly string[]) => Promise<void>
}

/**
 * Pas de dépendance à useI18n() (cf. useAdminExperienceTechnologies) : la page
 * appelante traduit `errorMessage.reason`, ce qui garde ce composable testable
 * sans instance i18n.
 *
 * `load()` ne prend plus de locale depuis la spec 0004 (D8) : le tableau du
 * backoffice affiche toutes les langues, le sélecteur de la page ne pilote
 * plus que les formulaires. La liste se charge donc une fois, à la création du
 * composable, et non plus à chaque changement de langue.
 */
export function useAdminQualityPrinciples(): UseAdminQualityPrinciplesResult {
  const repository = inject(ADMIN_QUALITY_PRINCIPLE_REPOSITORY)

  if (!repository) {
    throw new Error(
      "AdminQualityPrincipleRepository n'a pas été fourni. Vérifiez que app.provide(ADMIN_QUALITY_PRINCIPLE_REPOSITORY, ...) est bien appelé dans main.ts.",
    )
  }

  const principles = ref<readonly AdminQualityPrinciple[]>([])
  const isLoading = ref(true)
  const hasError = ref(false)
  const errorMessage = ref<AdminQualityError | null>(null)
  const requestGuard = createStaleRequestGuard()

  const load = async (): Promise<void> => {
    const token = requestGuard.begin()
    isLoading.value = true
    hasError.value = false

    try {
      const result = await repository.list()
      if (!requestGuard.isCurrent(token)) return
      principles.value = result
    } catch {
      if (requestGuard.isCurrent(token)) hasError.value = true
    } finally {
      if (requestGuard.isCurrent(token)) isLoading.value = false
    }
  }

  const runMutation = async (mutation: () => Promise<unknown>): Promise<void> => {
    errorMessage.value = null

    try {
      await mutation()
      await load()
    } catch (error) {
      errorMessage.value = error instanceof AdminQualityError ? error : new AdminQualityError('unknown', 'Unknown error')
    }
  }

  const create = (input: AdminQualityPrincipleInput): Promise<void> => runMutation(() => repository.create(input))

  const update = (id: string, input: AdminQualityPrincipleInput): Promise<void> => runMutation(() => repository.update(id, input))

  const remove = (id: string): Promise<void> => runMutation(() => repository.remove(id))

  /**
   * Volontairement hors de `runMutation` : ni rechargement ni absorption de
   * l'erreur ici. `useOrderDraft` recharge lui-même après un enregistrement
   * réussi, et il a besoin de recevoir l'`AdminOrderError` telle quelle pour
   * distinguer un ordre obsolète (D4) d'une panne — la convertir en
   * `AdminQualityError` lui retirerait cette information.
   */
  const reorder = (keys: readonly string[]): Promise<void> => repository.reorder(keys)

  void load()

  return { principles, isLoading, hasError, errorMessage, load, create, update, remove, reorder }
}
