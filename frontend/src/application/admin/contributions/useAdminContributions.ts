import { inject, ref, type InjectionKey, type Ref } from 'vue'
import type { AdminContribution } from '../../../domain/admin/contributions/entities/AdminContribution'
import type {
  AdminContributionInput,
  AdminContributionRepository,
} from '../../../domain/admin/contributions/repositories/AdminContributionRepository'
import { AdminContributionError } from '../../../domain/admin/contributions/errors/AdminContributionError'
import { useAdminLoad, type AdminLoadState } from '../shared/useAdminLoad'
import { visibleMutationError } from '../shared/visibleMutationError'

export const ADMIN_CONTRIBUTION_REPOSITORY: InjectionKey<AdminContributionRepository> = Symbol('AdminContributionRepository')

export interface UseAdminContributionsResult extends AdminLoadState {
  contributions: Ref<readonly AdminContribution[]>
  errorMessage: Ref<AdminContributionError | null>
  load: () => Promise<void>
  create: (input: AdminContributionInput) => Promise<boolean>
  update: (id: string, input: AdminContributionInput) => Promise<boolean>
  remove: (id: string) => Promise<boolean>
  reorder: (keys: readonly string[]) => Promise<void>
}

/**
 * Pas de dépendance à useI18n() (cf. useAdminExperienceTechnologies) : la page
 * appelante traduit `errorMessage.reason`, ce qui garde ce composable
 * testable sans instance i18n.
 *
 * `hasError` reflète un échec du chargement initial de la liste (affichage
 * plein écran) ; `errorMessage` reflète l'échec d'une mutation ponctuelle
 * (affiché près du formulaire) — distincts pour ne pas masquer une liste déjà
 * chargée derrière une erreur transitoire de formulaire.
 */
export function useAdminContributions(): UseAdminContributionsResult {
  const repository = inject(ADMIN_CONTRIBUTION_REPOSITORY)

  if (!repository) {
    throw new Error(
      "AdminContributionRepository n'a pas été fourni. " +
        'Vérifiez que app.provide(ADMIN_CONTRIBUTION_REPOSITORY, ...) est bien appelé dans main.ts.',
    )
  }

  const contributions = ref<readonly AdminContribution[]>([])
  const errorMessage = ref<AdminContributionError | null>(null)

  const { isLoading, hasError, load } = useAdminLoad({
    read: () => repository.list(),
    apply: (value) => {
      contributions.value = value
    },
  })

  const runMutation = async (mutation: () => Promise<unknown>): Promise<boolean> => {
    errorMessage.value = null

    try {
      await mutation()
      await load()

      return true
    } catch (error) {
      errorMessage.value = visibleMutationError(error, AdminContributionError, () => new AdminContributionError('unknown', 'Unknown error'))

      return false
    }
  }

  const create = (input: AdminContributionInput): Promise<boolean> => runMutation(() => repository.create(input))

  const update = (id: string, input: AdminContributionInput): Promise<boolean> => runMutation(() => repository.update(id, input))

  const remove = (id: string): Promise<boolean> => runMutation(() => repository.remove(id))

  /**
   * Volontairement hors de `runMutation` : ni rechargement ni absorption de
   * l'erreur ici. `useOrderDraft` recharge lui-même après un enregistrement
   * réussi, et il a besoin de recevoir l'`AdminOrderError` telle quelle pour
   * distinguer un ordre obsolète (D4) d'une panne — la convertir en
   * `AdminContributionError` lui retirerait cette information.
   */
  const reorder = (keys: readonly string[]): Promise<void> => repository.reorder(keys)

  void load()

  return { contributions, isLoading, hasError, errorMessage, load, create, update, remove, reorder }
}
