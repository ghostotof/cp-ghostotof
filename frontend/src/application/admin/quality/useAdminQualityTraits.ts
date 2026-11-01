import { inject, ref, type InjectionKey, type Ref } from 'vue'
import type { AdminQualityTrait } from '../../../domain/admin/quality/entities/AdminQualityTrait'
import type {
  AdminQualityTraitInput,
  AdminQualityTraitRepository,
} from '../../../domain/admin/quality/repositories/AdminQualityTraitRepository'
import { AdminQualityError } from '../../../domain/admin/quality/errors/AdminQualityError'
import { useAdminLoad, type AdminLoadState } from '../shared/useAdminLoad'
import { visibleMutationError } from '../shared/visibleMutationError'

export const ADMIN_QUALITY_TRAIT_REPOSITORY: InjectionKey<AdminQualityTraitRepository> = Symbol('AdminQualityTraitRepository')

export interface UseAdminQualityTraitsResult extends AdminLoadState {
  traits: Ref<readonly AdminQualityTrait[]>
  errorMessage: Ref<AdminQualityError | null>
  load: () => Promise<void>
  create: (input: AdminQualityTraitInput) => Promise<boolean>
  update: (id: string, input: AdminQualityTraitInput) => Promise<boolean>
  remove: (id: string) => Promise<boolean>
  reorder: (keys: readonly string[]) => Promise<void>
}

/**
 * Pas de dépendance à useI18n() (cf. useAdminExperienceTechnologies) : la page
 * appelante traduit `errorMessage.reason`, ce qui garde ce composable testable
 * sans instance i18n.
 *
 * `load()` ne prend pas de locale (spec 0004, D8) : le tableau du backoffice
 * affiche toutes les langues, le sélecteur de chaque section ne pilote que son
 * formulaire. La liste se charge donc une fois, à la création du composable, et
 * non à chaque changement de langue.
 */
export function useAdminQualityTraits(): UseAdminQualityTraitsResult {
  const repository = inject(ADMIN_QUALITY_TRAIT_REPOSITORY)

  if (!repository) {
    throw new Error(
      "AdminQualityTraitRepository n'a pas été fourni. Vérifiez que app.provide(ADMIN_QUALITY_TRAIT_REPOSITORY, ...) est bien appelé dans main.ts.",
    )
  }

  const traits = ref<readonly AdminQualityTrait[]>([])
  const errorMessage = ref<AdminQualityError | null>(null)

  const { isLoading, hasError, isLoaded, load } = useAdminLoad({
    read: () => repository.list(),
    apply: (value) => {
      traits.value = value
    },
  })

  const runMutation = async (mutation: () => Promise<unknown>): Promise<boolean> => {
    errorMessage.value = null

    try {
      await mutation()
      await load()

      return true
    } catch (error) {
      errorMessage.value = visibleMutationError(error, AdminQualityError, () => new AdminQualityError('unknown', 'Unknown error'))

      return false
    }
  }

  const create = (input: AdminQualityTraitInput): Promise<boolean> => runMutation(() => repository.create(input))

  const update = (id: string, input: AdminQualityTraitInput): Promise<boolean> => runMutation(() => repository.update(id, input))

  const remove = (id: string): Promise<boolean> => runMutation(() => repository.remove(id))

  /**
   * Volontairement hors de `runMutation` : ni rechargement ni absorption de
   * l'erreur ici. `useOrderDraft` recharge lui-même après un enregistrement
   * réussi, et il a besoin de recevoir l'`AdminOrderError` telle quelle pour
   * distinguer un ordre obsolète (D4) d'une panne — la convertir en
   * `AdminQualityError` lui retirerait cette information.
   */
  const reorder = (keys: readonly string[]): Promise<void> => repository.reorder(keys)

  void load()

  return { traits, isLoading, hasError, isLoaded, errorMessage, load, create, update, remove, reorder }
}
