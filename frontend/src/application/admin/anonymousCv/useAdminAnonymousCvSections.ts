import { inject, ref, type InjectionKey, type Ref } from 'vue'
import type { AdminAnonymousCvSection } from '../../../domain/admin/anonymousCv/entities/AdminAnonymousCvSection'
import type {
  AdminAnonymousCvSectionInput,
  AdminAnonymousCvSectionRepository,
} from '../../../domain/admin/anonymousCv/repositories/AdminAnonymousCvSectionRepository'
import { AdminAnonymousCvSectionError } from '../../../domain/admin/anonymousCv/errors/AdminAnonymousCvSectionError'
import { createStaleRequestGuard } from '../../shared/staleRequestGuard'

export const ADMIN_ANONYMOUS_CV_SECTION_REPOSITORY: InjectionKey<AdminAnonymousCvSectionRepository> = Symbol(
  'AdminAnonymousCvSectionRepository',
)

export interface UseAdminAnonymousCvSectionsResult {
  sections: Ref<readonly AdminAnonymousCvSection[]>
  isLoading: Ref<boolean>
  hasError: Ref<boolean>
  errorMessage: Ref<AdminAnonymousCvSectionError | null>
  load: () => Promise<void>
  create: (input: AdminAnonymousCvSectionInput) => Promise<void>
  update: (id: number, input: AdminAnonymousCvSectionInput) => Promise<void>
  remove: (id: number) => Promise<void>
}

/**
 * Même forme que useAdminIncidents : pas de dépendance à useI18n(), la page
 * traduit `errorMessage.reason`. `hasError` couvre l'échec du chargement
 * initial ; `errorMessage` celui d'une mutation — distincts, pour ne pas
 * masquer une liste chargée derrière une erreur de formulaire.
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
  const isLoading = ref(true)
  const hasError = ref(false)
  const errorMessage = ref<AdminAnonymousCvSectionError | null>(null)
  const requestGuard = createStaleRequestGuard()

  const load = async (): Promise<void> => {
    const token = requestGuard.begin()
    isLoading.value = true
    hasError.value = false

    try {
      const result = await repository.list()
      if (!requestGuard.isCurrent(token)) return
      sections.value = result
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
      errorMessage.value =
        error instanceof AdminAnonymousCvSectionError ? error : new AdminAnonymousCvSectionError('unknown', 'Unknown error')
    }
  }

  const create = (input: AdminAnonymousCvSectionInput): Promise<void> => runMutation(() => repository.create(input))

  const update = (id: number, input: AdminAnonymousCvSectionInput): Promise<void> =>
    runMutation(() => repository.update(id, input))

  const remove = (id: number): Promise<void> => runMutation(() => repository.remove(id))

  void load()

  return { sections, isLoading, hasError, errorMessage, load, create, update, remove }
}
