import { inject, ref, type InjectionKey, type Ref } from 'vue'
import type { AdminExperienceTechnology } from '../../../domain/admin/technologies/entities/AdminExperienceTechnology'
import type {
  AdminExperienceTechnologyInput,
  AdminExperienceTechnologyRepository,
} from '../../../domain/admin/technologies/repositories/AdminExperienceTechnologyRepository'
import { AdminExperienceTechnologyError } from '../../../domain/admin/technologies/errors/AdminExperienceTechnologyError'

export const ADMIN_EXPERIENCE_TECHNOLOGY_REPOSITORY: InjectionKey<AdminExperienceTechnologyRepository> = Symbol(
  'AdminExperienceTechnologyRepository',
)

export interface UseAdminExperienceTechnologiesResult {
  technologies: Ref<readonly AdminExperienceTechnology[]>
  isLoading: Ref<boolean>
  hasError: Ref<boolean>
  errorMessage: Ref<AdminExperienceTechnologyError | null>
  load: () => Promise<void>
  create: (input: AdminExperienceTechnologyInput) => Promise<void>
  update: (id: number, input: AdminExperienceTechnologyInput) => Promise<void>
  remove: (id: number) => Promise<void>
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
  const isLoading = ref(true)
  const hasError = ref(false)
  const errorMessage = ref<AdminExperienceTechnologyError | null>(null)

  const load = async (): Promise<void> => {
    isLoading.value = true
    hasError.value = false

    try {
      technologies.value = await repository.list()
    } catch {
      hasError.value = true
    } finally {
      isLoading.value = false
    }
  }

  const runMutation = async (mutation: () => Promise<unknown>): Promise<void> => {
    errorMessage.value = null

    try {
      await mutation()
      await load()
    } catch (error) {
      errorMessage.value = error instanceof AdminExperienceTechnologyError ? error : new AdminExperienceTechnologyError('unknown', 'Unknown error')
    }
  }

  const create = (input: AdminExperienceTechnologyInput): Promise<void> => runMutation(() => repository.create(input))

  const update = (id: number, input: AdminExperienceTechnologyInput): Promise<void> => runMutation(() => repository.update(id, input))

  const remove = (id: number): Promise<void> => runMutation(() => repository.remove(id))

  void load()

  return { technologies, isLoading, hasError, errorMessage, load, create, update, remove }
}
