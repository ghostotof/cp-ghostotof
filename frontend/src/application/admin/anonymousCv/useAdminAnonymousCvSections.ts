import { inject, ref, type InjectionKey, type Ref } from 'vue'
import type { AdminAnonymousCvSection } from '../../../domain/admin/anonymousCv/entities/AdminAnonymousCvSection'
import type {
  AdminAnonymousCvSectionInput,
  AdminAnonymousCvSectionRepository,
} from '../../../domain/admin/anonymousCv/repositories/AdminAnonymousCvSectionRepository'
import { AdminAnonymousCvSectionError } from '../../../domain/admin/anonymousCv/errors/AdminAnonymousCvSectionError'
import { useAdminLoad, type AdminLoadState } from '../shared/useAdminLoad'
import { visibleMutationError } from '../shared/visibleMutationError'

export const ADMIN_ANONYMOUS_CV_SECTION_REPOSITORY: InjectionKey<AdminAnonymousCvSectionRepository> = Symbol(
  'AdminAnonymousCvSectionRepository',
)

export interface UseAdminAnonymousCvSectionsResult extends AdminLoadState {
  sections: Ref<readonly AdminAnonymousCvSection[]>
  errorMessage: Ref<AdminAnonymousCvSectionError | null>
  load: () => Promise<void>
  create: (input: AdminAnonymousCvSectionInput) => Promise<boolean>
  update: (id: string, input: AdminAnonymousCvSectionInput) => Promise<boolean>
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
export function useAdminAnonymousCvSections(): UseAdminAnonymousCvSectionsResult {
  const repository = inject(ADMIN_ANONYMOUS_CV_SECTION_REPOSITORY)

  if (!repository) {
    throw new Error(
      "AdminAnonymousCvSectionRepository n'a pas été fourni. " +
        'Vérifiez que app.provide(ADMIN_ANONYMOUS_CV_SECTION_REPOSITORY, ...) est bien appelé dans main.ts.',
    )
  }

  const sections = ref<readonly AdminAnonymousCvSection[]>([])
  const errorMessage = ref<AdminAnonymousCvSectionError | null>(null)

  const { isLoading, hasError, isLoaded, load } = useAdminLoad({
    read: () => repository.list(),
    apply: (value) => {
      sections.value = value
    },
  })

  const runMutation = async (mutation: () => Promise<unknown>): Promise<boolean> => {
    errorMessage.value = null

    try {
      await mutation()
      await load()

      return true
    } catch (error) {
      errorMessage.value = visibleMutationError(
        error,
        AdminAnonymousCvSectionError,
        () => new AdminAnonymousCvSectionError('unknown', 'Unknown error'),
      )

      return false
    }
  }

  const create = (input: AdminAnonymousCvSectionInput): Promise<boolean> => runMutation(() => repository.create(input))

  const update = (id: string, input: AdminAnonymousCvSectionInput): Promise<boolean> =>
    runMutation(() => repository.update(id, input))

  const remove = (id: string): Promise<boolean> => runMutation(() => repository.remove(id))

  /**
   * Volontairement hors de `runMutation` : ni rechargement ni absorption de
   * l'erreur ici. `useOrderDraft` recharge lui-même après un enregistrement
   * réussi, et il a besoin de recevoir l'`AdminOrderError` telle quelle pour
   * distinguer un ordre obsolète (D4) d'une panne — la convertir en
   * `AdminAnonymousCvSectionError` lui retirerait cette information.
   */
  const reorder = (keys: readonly string[]): Promise<void> => repository.reorder(keys)

  void load()

  return { sections, isLoading, hasError, isLoaded, errorMessage, load, create, update, remove, reorder }
}
