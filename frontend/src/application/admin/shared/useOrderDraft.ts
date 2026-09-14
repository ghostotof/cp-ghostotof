import { computed, ref, watch, type ComputedRef, type Ref } from 'vue'
import { moveKey } from '../../../domain/admin/shared/ordering/moveKey'
import { AdminOrderError, type AdminOrderErrorReason } from '../../../domain/admin/shared/errors/AdminOrderError'

export interface UseOrderDraftOptions {
  /** La liste ordonnée des clés telle que le serveur la connaît (un id, ou un `translationGroup`). */
  serverKeys: Ref<readonly string[]> | (() => readonly string[])
  /** `PUT …/order` : envoie l'ordre du brouillon. Rejette avec `AdminOrderError` (repository) sur échec. */
  reorder: (keys: readonly string[]) => Promise<void>
  /** Recharge la liste depuis le serveur — appelée après un `save` réussi, et après un 422 obsolète. */
  reload: () => Promise<void>
}

export interface UseOrderDraftResult {
  draft: Ref<readonly string[]>
  isDirty: ComputedRef<boolean>
  isSaving: Ref<boolean>
  errorReason: Ref<AdminOrderErrorReason | null>
  move: (from: number, to: number) => void
  reset: () => void
  save: () => Promise<void>
}

/**
 * Le brouillon d'ordre d'une page admin (D6) : tant qu'il n'est pas modifié,
 * il suit `serverKeys` (la liste peut changer sous ses pieds — un ajout
 * ailleurs, un rafraîchissement) ; dès qu'un `move` a lieu, il s'en détache
 * jusqu'à `reset()` ou `save()`, pour ne jamais écraser silencieusement le
 * geste de l'admin en cours de glisser-déposer.
 *
 * `save()` n'appelle jamais `reorder` puis abandonne : sur un 422 obsolète
 * (D4 — l'ensemble des clés a changé entre le chargement et l'enregistrement),
 * il recharge la liste et réinitialise le brouillon sur la nouvelle valeur,
 * `errorReason` restant exposé pour que la page l'annonce. Sur toute autre
 * erreur, le brouillon reste tel quel : l'admin peut réessayer sans perdre
 * son geste.
 */
export function useOrderDraft(options: UseOrderDraftOptions): UseOrderDraftResult {
  const readServerKeys = (): readonly string[] =>
    'function' === typeof options.serverKeys ? options.serverKeys() : options.serverKeys.value

  const draft = ref<readonly string[]>([...readServerKeys()]) as Ref<readonly string[]>
  const dirty = ref(false)
  const isSaving = ref(false)
  const errorReason = ref<AdminOrderErrorReason | null>(null)

  watch(readServerKeys, (next) => {
    if (!dirty.value) {
      draft.value = [...next]
    }
  })

  function move(from: number, to: number): void {
    const moved = moveKey(draft.value, from, to)

    // `moveKey` renvoie une copie inchangée hors bornes ou quand from === to
    // (cf. sa docstring) : comparer avant de marquer le brouillon modifié
    // évite un « Enregistrer » activé pour un geste qui n'a rien déplacé.
    if (moved.every((key, index) => key === draft.value[index])) {
      return
    }

    draft.value = moved
    dirty.value = true
  }

  function reset(): void {
    draft.value = [...readServerKeys()]
    dirty.value = false
    errorReason.value = null
  }

  async function save(): Promise<void> {
    // Garde de réentrance : un double-clic (ou un double appel programmatique)
    // pendant un enregistrement en cours ne doit jamais déclencher un second
    // `reorder` — `OrderToolbar` désactive déjà le bouton pendant `isSaving`,
    // mais rien n'empêche un appelant d'invoquer `save()` directement.
    if (isSaving.value) {
      return
    }

    isSaving.value = true
    errorReason.value = null

    try {
      await options.reorder(draft.value)
      await options.reload()
      dirty.value = false
    } catch (error) {
      const reason: AdminOrderErrorReason = error instanceof AdminOrderError ? error.reason : 'unknown'
      errorReason.value = reason

      if ('stale-order' === reason) {
        await options.reload()
        draft.value = [...readServerKeys()]
        dirty.value = false
      }
    } finally {
      isSaving.value = false
    }
  }

  return {
    draft,
    isDirty: computed(() => dirty.value),
    isSaving,
    errorReason,
    move,
    reset,
    save,
  }
}
