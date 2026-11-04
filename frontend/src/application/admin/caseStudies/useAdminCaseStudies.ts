import { inject, ref, type InjectionKey, type Ref } from 'vue'
import type { AdminCaseStudy } from '../../../domain/admin/caseStudies/entities/AdminCaseStudy'
import type {
  AdminCaseStudyInput,
  AdminCaseStudyRepository,
} from '../../../domain/admin/caseStudies/repositories/AdminCaseStudyRepository'
import { AdminCaseStudyError } from '../../../domain/admin/caseStudies/errors/AdminCaseStudyError'
import { useAdminLoad, type AdminLoadState } from '../shared/useAdminLoad'
import { visibleMutationError } from '../shared/visibleMutationError'

export const ADMIN_CASE_STUDY_REPOSITORY: InjectionKey<AdminCaseStudyRepository> = Symbol(
  'AdminCaseStudyRepository',
)

export interface UseAdminCaseStudiesResult extends AdminLoadState {
  caseStudies: Ref<readonly AdminCaseStudy[]>
  errorMessage: Ref<AdminCaseStudyError | null>
  load: () => Promise<void>
  create: (input: AdminCaseStudyInput) => Promise<boolean>
  update: (id: string, input: AdminCaseStudyInput) => Promise<boolean>
  remove: (id: string) => Promise<boolean>
  reorder: (keys: readonly string[]) => Promise<void>
}

/**
 * Pas de dépendance à useI18n() (cf. useAdminContributions) : la page
 * appelante traduit `errorMessage.reason`, ce qui garde ce composable
 * testable sans instance i18n.
 *
 * `hasError` reflète un échec du chargement initial de la liste (affichage
 * plein écran) ; `errorMessage` reflète l'échec d'une mutation ponctuelle
 * (affiché près du formulaire) — distincts pour ne pas masquer une liste déjà
 * chargée derrière une erreur transitoire de formulaire.
 */
export function useAdminCaseStudies(): UseAdminCaseStudiesResult {
  const repository = inject(ADMIN_CASE_STUDY_REPOSITORY)

  if (!repository) {
    throw new Error(
      "AdminCaseStudyRepository n'a pas été fourni. " +
        'Vérifiez que app.provide(ADMIN_CASE_STUDY_REPOSITORY, ...) est bien appelé dans main.ts.',
    )
  }

  const caseStudies = ref<readonly AdminCaseStudy[]>([])
  const errorMessage = ref<AdminCaseStudyError | null>(null)

  const { isLoading, hasError, isLoaded, load } = useAdminLoad({
    read: () => repository.list(),
    apply: (value) => {
      caseStudies.value = value
    },
  })

  const runMutation = async (mutation: () => Promise<unknown>): Promise<boolean> => {
    errorMessage.value = null

    try {
      await mutation()
      await load()

      return true
    } catch (error) {
      errorMessage.value = visibleMutationError(error, AdminCaseStudyError, () => new AdminCaseStudyError('unknown', 'Unknown error'))

      return false
    }
  }

  const create = (input: AdminCaseStudyInput): Promise<boolean> => runMutation(() => repository.create(input))

  const update = (id: string, input: AdminCaseStudyInput): Promise<boolean> =>
    runMutation(() => repository.update(id, input))

  const remove = (id: string): Promise<boolean> => runMutation(() => repository.remove(id))

  /**
   * Volontairement hors de `runMutation` : ni rechargement ni absorption de
   * l'erreur ici. `useOrderDraft` recharge lui-même après un enregistrement
   * réussi, et il a besoin de recevoir l'`AdminOrderError` telle quelle pour
   * distinguer un ordre obsolète (D4) d'une panne — la convertir en
   * `AdminCaseStudyError` lui retirerait cette information.
   */
  const reorder = (keys: readonly string[]): Promise<void> => repository.reorder(keys)

  void load()

  return { caseStudies, isLoading, hasError, isLoaded, errorMessage, load, create, update, remove, reorder }
}
