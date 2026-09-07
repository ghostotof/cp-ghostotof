import { inject, ref, type InjectionKey, type Ref } from 'vue'
import type { AdminIncident } from '../../../domain/admin/incidents/entities/AdminIncident'
import type {
  AdminIncidentInput,
  AdminIncidentRepository,
} from '../../../domain/admin/incidents/repositories/AdminIncidentRepository'
import { AdminIncidentError } from '../../../domain/admin/incidents/errors/AdminIncidentError'
import { createStaleRequestGuard } from '../../shared/staleRequestGuard'

export const ADMIN_INCIDENT_REPOSITORY: InjectionKey<AdminIncidentRepository> = Symbol('AdminIncidentRepository')

export interface UseAdminIncidentsResult {
  incidents: Ref<readonly AdminIncident[]>
  isLoading: Ref<boolean>
  hasError: Ref<boolean>
  errorMessage: Ref<AdminIncidentError | null>
  load: () => Promise<void>
  create: (input: AdminIncidentInput) => Promise<void>
  update: (id: number, input: AdminIncidentInput) => Promise<void>
  remove: (id: number) => Promise<void>
}

/**
 * Pas de dépendance à useI18n() (cf. useAdminExperienceTechnologies) : la page
 * appelante traduit `errorMessage.reason`.
 *
 * `hasError` couvre l'échec du chargement initial ; `errorMessage` celui d'une
 * mutation — distincts, pour ne pas masquer une liste chargée derrière une
 * erreur de formulaire.
 */
export function useAdminIncidents(): UseAdminIncidentsResult {
  const repository = inject(ADMIN_INCIDENT_REPOSITORY)

  if (!repository) {
    throw new Error(
      "AdminIncidentRepository n'a pas été fourni. " +
        'Vérifiez que app.provide(ADMIN_INCIDENT_REPOSITORY, ...) est bien appelé dans main.ts.',
    )
  }

  const incidents = ref<readonly AdminIncident[]>([])
  const isLoading = ref(true)
  const hasError = ref(false)
  const errorMessage = ref<AdminIncidentError | null>(null)
  const requestGuard = createStaleRequestGuard()

  const load = async (): Promise<void> => {
    const token = requestGuard.begin()
    isLoading.value = true
    hasError.value = false

    try {
      const result = await repository.list()
      if (!requestGuard.isCurrent(token)) return
      incidents.value = result
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
      errorMessage.value = error instanceof AdminIncidentError ? error : new AdminIncidentError('unknown', 'Unknown error')
    }
  }

  const create = (input: AdminIncidentInput): Promise<void> => runMutation(() => repository.create(input))

  const update = (id: number, input: AdminIncidentInput): Promise<void> => runMutation(() => repository.update(id, input))

  const remove = (id: number): Promise<void> => runMutation(() => repository.remove(id))

  void load()

  return { incidents, isLoading, hasError, errorMessage, load, create, update, remove }
}
