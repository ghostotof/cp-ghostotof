import { inject, ref, type InjectionKey, type Ref } from 'vue'
import type { AdminIncident } from '../../../domain/admin/incidents/entities/AdminIncident'
import type {
  AdminIncidentInput,
  AdminIncidentRepository,
} from '../../../domain/admin/incidents/repositories/AdminIncidentRepository'
import { AdminIncidentError } from '../../../domain/admin/incidents/errors/AdminIncidentError'
import { useAdminLoad, type AdminLoadState } from '../shared/useAdminLoad'
import { visibleMutationError } from '../shared/visibleMutationError'

export const ADMIN_INCIDENT_REPOSITORY: InjectionKey<AdminIncidentRepository> = Symbol('AdminIncidentRepository')

export interface UseAdminIncidentsResult extends AdminLoadState {
  incidents: Ref<readonly AdminIncident[]>
  errorMessage: Ref<AdminIncidentError | null>
  load: () => Promise<void>
  create: (input: AdminIncidentInput) => Promise<boolean>
  update: (id: string, input: AdminIncidentInput) => Promise<boolean>
  remove: (id: string) => Promise<boolean>
  reorder: (keys: readonly string[]) => Promise<void>
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
  const errorMessage = ref<AdminIncidentError | null>(null)

  const { isLoading, hasError, isLoaded, load } = useAdminLoad({
    read: () => repository.list(),
    apply: (value) => {
      incidents.value = value
    },
  })

  const runMutation = async (mutation: () => Promise<unknown>): Promise<boolean> => {
    errorMessage.value = null

    try {
      await mutation()
      await load()

      return true
    } catch (error) {
      errorMessage.value = visibleMutationError(error, AdminIncidentError, () => new AdminIncidentError('unknown', 'Unknown error'))

      return false
    }
  }

  const create = (input: AdminIncidentInput): Promise<boolean> => runMutation(() => repository.create(input))

  const update = (id: string, input: AdminIncidentInput): Promise<boolean> => runMutation(() => repository.update(id, input))

  const remove = (id: string): Promise<boolean> => runMutation(() => repository.remove(id))

  /**
   * Volontairement hors de `runMutation` : ni rechargement ni absorption de
   * l'erreur ici. `useOrderDraft` recharge lui-même après un enregistrement
   * réussi, et il a besoin de recevoir l'`AdminOrderError` telle quelle pour
   * distinguer un ordre obsolète (D4) d'une panne — la convertir en
   * `AdminIncidentError` lui retirerait cette information.
   */
  const reorder = (keys: readonly string[]): Promise<void> => repository.reorder(keys)

  void load()

  return { incidents, isLoading, hasError, isLoaded, errorMessage, load, create, update, remove, reorder }
}
