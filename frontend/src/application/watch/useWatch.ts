import { inject, ref, type InjectionKey, type Ref } from 'vue'
import type { WatchSnapshot } from '../../domain/watch/entities/WatchSnapshot'
import type { WatchRepository } from '../../domain/watch/repositories/WatchRepository'

export const WATCH_REPOSITORY: InjectionKey<WatchRepository> = Symbol('WatchRepository')

export interface UseWatchResult {
  snapshot: Ref<WatchSnapshot | null>
  isLoading: Ref<boolean>
  hasError: Ref<boolean>
}

/**
 * Charge l'état de la veille technique.
 *
 * Contrairement aux autres composables de contenu, il ne se rattache pas à la
 * locale : les données ne sont pas traduites (décision D6), il n'y a donc rien
 * à recharger quand la langue change — et par conséquent aucune requête
 * concurrente à départager, d'où l'absence de garde anti-course ici.
 */
export function useWatch(): UseWatchResult {
  const repository = inject(WATCH_REPOSITORY)

  if (!repository) {
    throw new Error(
      "WatchRepository n'a pas été fourni. " +
        'Vérifiez que app.provide(WATCH_REPOSITORY, ...) est bien appelé dans main.ts.',
    )
  }

  const snapshot = ref<WatchSnapshot | null>(null)
  const isLoading = ref(true)
  const hasError = ref(false)

  const load = async (): Promise<void> => {
    isLoading.value = true
    hasError.value = false

    try {
      snapshot.value = await repository.get()
    } catch {
      hasError.value = true
    } finally {
      isLoading.value = false
    }
  }

  void load()

  return { snapshot, isLoading, hasError }
}
