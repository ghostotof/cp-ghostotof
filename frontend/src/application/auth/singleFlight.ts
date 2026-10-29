/**
 * Un emplacement pour une relecture de `/api/me` : **au plus une en vol à la fois** (#748, R41, St4-4).
 *
 * Pourquoi : `recheckEndedSession` et `rereadAfterRefusal` partagent leur promesse entre tous les appelants de
 * `useAuth()` (un retour d'onglet et une page, ou deux refus ensemble, n'envoient qu'un `/api/me`). La libération
 * était écrite deux fois, dans un `finally` interne à la tâche : un `me()` qui lève de façon **synchrone** terminait la
 * tâche avant que sa promesse soit posée, le `finally` remettait `null`, puis la promesse déjà réglée était posée — et
 * toute relecture suivante la rendait sans appeler `me()` (R15 avait corrigé le défaut sur `rereadAfterRefusal` seul).
 * Écrit ici une fois : la libération est un `.finally` posé **après** la pose, hors de la tâche.
 */
export interface SingleFlight<T> {
  /**
   * Rend la promesse en vol sans appeler `task` ; sinon appelle `task` (un `throw` synchrone devient une promesse
   * rejetée), la pose, et la libère quand elle se règle, résolue ou rejetée.
   */
  run(task: () => Promise<T>): Promise<T>
  /** Oublie la promesse en vol. Réservée aux tests (`reset…ForTests`), jamais appelée par `src/`. */
  clear(): void
}

export function createSingleFlight<T>(): SingleFlight<T> {
  let inFlight: Promise<T> | null = null

  return {
    run(task) {
      if (null !== inFlight) {
        return inFlight
      }

      let pending: Promise<T>
      try {
        pending = task()
      } catch (error) {
        // Levé de façon synchrone : rien n'est posé, la place reste libre, l'appelant reçoit un rejet comme pour
        // n'importe quelle autre panne.
        return Promise.reject(error)
      }
      inFlight = pending
      // Libérée hors de la tâche, après la pose. `.catch` au bout de la copie : le rejet est l'affaire de l'appelant
      // (qui reçoit `pending`), pas de cette chaîne de nettoyage, qui ne doit pas créer un rejet non géré.
      pending
        .finally(() => {
          if (inFlight === pending) {
            inFlight = null
          }
        })
        .catch(() => undefined)

      return pending
    },
    clear() {
      inFlight = null
    },
  }
}
