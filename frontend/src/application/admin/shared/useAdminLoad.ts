import { ref, type Ref } from 'vue'
import { SessionEndedError } from '../../../domain/auth/errors/SessionEndedError'
import { createStaleRequestGuard } from '../../shared/staleRequestGuard'

/** Ce que tout composable d'administration expose d'un chargement, pour que la page le lise partout de la même façon. */
export interface AdminLoadState {
  isLoading: Ref<boolean>
  hasError: Ref<boolean>
}

export interface AdminLoadOptions<T, K> {
  /** La lecture (`repository.list()`, `repository.get(locale)`), qui rejette en cas d'échec. */
  read: (key: K) => Promise<T>
  /** Pose la valeur lue dans l'état du composable. Jamais appelée pour une réponse périmée ni un échec. */
  apply: (value: T) => void
}

/**
 * Le chargement d'une ressource d'administration, écrit **une fois** (#748, R3) pour les treize composables :
 * `isLoading` part à `true`, une réponse (ou un rejet) plus ancienne que le dernier `load()` est jetée
 * (« Réessayer » pendant une relecture, un sélecteur de locale cliqué en rafale), et tout rejet lève `hasError`
 * — **sauf `SessionEndedError`**, levée par `BackofficeHttpClient` : une session finie n'est pas un échec de
 * chargement, le bandeau de session finie (`SessionEndedBanner`) l'annonce seul, et la valeur déjà lue reste
 * affichée. Un composable qui chargerait sans passer ici devrait réécrire ce filtre, et l'oublier doublerait
 * le bandeau d'une alerte de page.
 *
 * `K` est la clé de la lecture : `void` pour une liste, la locale pour le singleton des réglages « À propos ».
 */
export function useAdminLoad<T, K = void>(options: AdminLoadOptions<T, K>): AdminLoadState & { load: (key: K) => Promise<void> } {
  const isLoading = ref(true)
  const hasError = ref(false)
  const requestGuard = createStaleRequestGuard()

  const load = async (key: K): Promise<void> => {
    const token = requestGuard.begin()
    isLoading.value = true
    hasError.value = false

    try {
      const value = await options.read(key)
      if (!requestGuard.isCurrent(token)) return
      options.apply(value)
    } catch (error) {
      if (requestGuard.isCurrent(token) && !(error instanceof SessionEndedError)) hasError.value = true
    } finally {
      if (requestGuard.isCurrent(token)) isLoading.value = false
    }
  }

  return { isLoading, hasError, load }
}
