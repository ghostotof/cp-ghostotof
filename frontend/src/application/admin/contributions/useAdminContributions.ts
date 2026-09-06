import { inject, ref, type InjectionKey, type Ref } from 'vue'
import type { AdminContribution } from '../../../domain/admin/contributions/entities/AdminContribution'
import type {
  AdminContributionInput,
  AdminContributionRepository,
} from '../../../domain/admin/contributions/repositories/AdminContributionRepository'
import { AdminContributionError } from '../../../domain/admin/contributions/errors/AdminContributionError'
import { createStaleRequestGuard } from '../../shared/staleRequestGuard'

export const ADMIN_CONTRIBUTION_REPOSITORY: InjectionKey<AdminContributionRepository> = Symbol('AdminContributionRepository')

export interface UseAdminContributionsResult {
  contributions: Ref<readonly AdminContribution[]>
  isLoading: Ref<boolean>
  hasError: Ref<boolean>
  errorMessage: Ref<AdminContributionError | null>
  load: () => Promise<void>
  create: (input: AdminContributionInput) => Promise<void>
  update: (id: number, input: AdminContributionInput) => Promise<void>
  remove: (id: number) => Promise<void>
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
  const isLoading = ref(true)
  const hasError = ref(false)
  const errorMessage = ref<AdminContributionError | null>(null)
  const requestGuard = createStaleRequestGuard()

  const load = async (): Promise<void> => {
    const token = requestGuard.begin()
    isLoading.value = true
    hasError.value = false

    try {
      const result = await repository.list()
      if (!requestGuard.isCurrent(token)) return
      contributions.value = result
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
      errorMessage.value = error instanceof AdminContributionError ? error : new AdminContributionError('unknown', 'Unknown error')
    }
  }

  const create = (input: AdminContributionInput): Promise<void> => runMutation(() => repository.create(input))

  const update = (id: number, input: AdminContributionInput): Promise<void> => runMutation(() => repository.update(id, input))

  const remove = (id: number): Promise<void> => runMutation(() => repository.remove(id))

  void load()

  return { contributions, isLoading, hasError, errorMessage, load, create, update, remove }
}
