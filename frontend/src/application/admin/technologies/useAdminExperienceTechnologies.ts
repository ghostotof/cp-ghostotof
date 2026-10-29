import { inject, ref, type InjectionKey, type Ref } from 'vue'
import type { AdminExperienceTechnology } from '../../../domain/admin/technologies/entities/AdminExperienceTechnology'
import type {
  AdminExperienceTechnologyInput,
  AdminExperienceTechnologyRepository,
} from '../../../domain/admin/technologies/repositories/AdminExperienceTechnologyRepository'
import { AdminExperienceTechnologyError } from '../../../domain/admin/technologies/errors/AdminExperienceTechnologyError'
import { useAdminLoad, type AdminLoadState } from '../shared/useAdminLoad'
import { visibleMutationError } from '../shared/visibleMutationError'

export const ADMIN_EXPERIENCE_TECHNOLOGY_REPOSITORY: InjectionKey<AdminExperienceTechnologyRepository> = Symbol(
  'AdminExperienceTechnologyRepository',
)

export interface UseAdminExperienceTechnologiesResult extends AdminLoadState {
  technologies: Ref<readonly AdminExperienceTechnology[]>
  errorMessage: Ref<AdminExperienceTechnologyError | null>
  load: () => Promise<void>
  create: (input: AdminExperienceTechnologyInput) => Promise<boolean>
  update: (id: string, input: AdminExperienceTechnologyInput) => Promise<boolean>
  remove: (id: string) => Promise<boolean>
}

/**
 * `hasError` reflète un échec du chargement initial de la liste (affichage
 * plein écran) ; `errorMessage` reflète l'échec d'une mutation ponctuelle
 * (create/update/remove, affiché près du formulaire) — les deux sont
 * distincts pour ne pas masquer une liste déjà chargée derrière un message
 * d'erreur transitoire de formulaire.
 */
export function useAdminExperienceTechnologies(): UseAdminExperienceTechnologiesResult {
  const repository = inject(ADMIN_EXPERIENCE_TECHNOLOGY_REPOSITORY)

  if (!repository) {
    throw new Error(
      "AdminExperienceTechnologyRepository n'a pas été fourni. " +
        'Vérifiez que app.provide(ADMIN_EXPERIENCE_TECHNOLOGY_REPOSITORY, ...) est bien appelé dans main.ts.',
    )
  }

  const technologies = ref<readonly AdminExperienceTechnology[]>([])
  const errorMessage = ref<AdminExperienceTechnologyError | null>(null)

  const { isLoading, hasError, load } = useAdminLoad({
    read: () => repository.list(),
    apply: (value) => {
      technologies.value = value
    },
  })

  const runMutation = async (mutation: () => Promise<unknown>): Promise<boolean> => {
    errorMessage.value = null

    try {
      await mutation()
      await load()

      return true
    } catch (error) {
      errorMessage.value = visibleMutationError(error, AdminExperienceTechnologyError, () => new AdminExperienceTechnologyError('unknown', 'Unknown error'))

      return false
    }
  }

  const create = (input: AdminExperienceTechnologyInput): Promise<boolean> => runMutation(() => repository.create(input))

  const update = (id: string, input: AdminExperienceTechnologyInput): Promise<boolean> => runMutation(() => repository.update(id, input))

  const remove = (id: string): Promise<boolean> => runMutation(() => repository.remove(id))

  void load()

  return { technologies, isLoading, hasError, errorMessage, load, create, update, remove }
}
