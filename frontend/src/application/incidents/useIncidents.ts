import { inject, ref, watch, type InjectionKey, type Ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Incident } from '../../domain/incidents/entities/Incident'
import type { IncidentRepository } from '../../domain/incidents/repositories/IncidentRepository'
import type { Locale } from '../../domain/portfolio/entities/Locale'
import { createStaleRequestGuard } from '../shared/staleRequestGuard'

export const INCIDENT_REPOSITORY: InjectionKey<IncidentRepository> = Symbol('IncidentRepository')

export interface UseIncidentsResult {
  incidents: Ref<readonly Incident[]>
  isLoading: Ref<boolean>
  hasError: Ref<boolean>
}

/** Charge le journal des incidents, et recharge à chaque changement de locale. */
export function useIncidents(): UseIncidentsResult {
  const repository = inject(INCIDENT_REPOSITORY)

  if (!repository) {
    throw new Error(
      "IncidentRepository n'a pas été fourni. " +
        'Vérifiez que app.provide(INCIDENT_REPOSITORY, ...) est bien appelé dans main.ts.',
    )
  }

  const { locale } = useI18n()
  const incidents = ref<readonly Incident[]>([])
  const isLoading = ref(true)
  const hasError = ref(false)
  const requestGuard = createStaleRequestGuard()

  const load = async (): Promise<void> => {
    const token = requestGuard.begin()
    isLoading.value = true
    hasError.value = false

    try {
      const loaded = await repository.list(locale.value as Locale)
      if (!requestGuard.isCurrent(token)) return
      incidents.value = loaded
    } catch {
      if (requestGuard.isCurrent(token)) hasError.value = true
    } finally {
      if (requestGuard.isCurrent(token)) isLoading.value = false
    }
  }

  watch(locale, load, { immediate: true })

  return { incidents, isLoading, hasError }
}
