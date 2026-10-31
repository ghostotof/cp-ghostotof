import { ref, shallowRef, type Ref } from 'vue'
import { AccessForbiddenError } from '../../../domain/auth/errors/AccessForbiddenError'
import { SessionEndedError } from '../../../domain/auth/errors/SessionEndedError'
import { onSessionRestored } from '../../auth/useAuth'
import { createStaleRequestGuard } from '../../shared/staleRequestGuard'

/** Ce que tout composable d'administration expose d'un chargement, pour que la page le lise partout de la même façon. */
export interface AdminLoadState {
  isLoading: Ref<boolean>
  hasError: Ref<boolean>
  /**
   * Vrai quand la valeur de la clé courante a été lue et posée. **Faux n'est pas « vide »** : avant la première
   * lecture, après une première lecture en session finie, et dès qu'une clé nouvelle commence à se lire, la
   * valeur n'est pas celle qu'on croit montrer. Une page ne dit « aucune … » que si `isLoaded` est vrai.
   */
  isLoaded: Ref<boolean>
}

export interface AdminLoadOptions<T, K> {
  /** La lecture (`repository.list()`, `repository.get(locale)`), qui rejette en cas d'échec. */
  read: (key: K) => Promise<T>
  /** Pose la valeur lue dans l'état du composable. Jamais appelée pour une réponse périmée ni un échec. */
  apply: (value: T) => void
  /**
   * Vide la valeur détenue. Appelée quand `load(clé)` commence avec une clé **différente** de celle de la valeur
   * détenue (`Object.is`) : sans elle, la valeur de l'ancienne clé resterait posée sous la nouvelle tant que la
   * lecture n'a pas répondu — ou pour toujours, si elle finit en session finie.
   */
  clear?: () => void
}

/**
 * Le chargement d'une ressource d'administration, écrit **une fois** (#748, R3) pour les treize composables :
 * `isLoading` part à `true`, une réponse (ou un rejet) plus ancienne que le dernier `load()` est jetée
 * (une relecture lancée pendant une autre, un sélecteur de locale cliqué en rafale), et tout rejet lève `hasError`
 * — **sauf `SessionEndedError`**, levée par `BackofficeHttpClient` : une session finie n'est pas un échec de
 * chargement, le bandeau de session finie (`SessionEndedBanner`) l'annonce seul, et la valeur déjà lue reste
 * affichée. Un composable qui chargerait sans passer ici devrait réécrire ce filtre, et l'oublier doublerait
 * le bandeau d'une alerte de page. **Ni `AccessForbiddenError`** (#748, R17) : un refus réel du backoffice est
 * annoncé par l'alerte « accès refusé » d'`AdminLayout`, la page ne lève pas `hasError` ; et comme le refus ne tient
 * pas à la session, rien n'est relu à son retour (`interrupted` reste vide).
 *
 * **Non chargé n'est pas vide** (R4) : `isLoaded` ne devient vrai qu'une fois la valeur posée, et une clé nouvelle
 * (`K`) vide d'abord celle de l'ancienne (`clear`). Le seul composable à clé, les réglages « À propos »,
 * montrait sinon le formulaire d'une locale sous l'en-tête d'une autre, et l'enregistrait par-dessus.
 *
 * **Au retour de la session, la lecture interrompue est relue, une fois, avec sa clé** : tant que la dernière
 * lecture a fini en session finie, le premier retour de la session (`onSessionRestored` de `useAuth`, le signal
 * qu'émet `useAuth` quand un compte revient) relance `load(clé)`. C'est le seul signal de retour qui existe : un
 * mécanisme qui relirait la session (la revérification au `focus`) n'a qu'à la rétablir, il ne relit jamais les
 * listes lui-même, et il ne peut pas doubler cette relecture.
 *
 * **La clé courante a une source, ici** (R20) : la dernière clé passée à `load()`, que la page montre ou est en train
 * de lire. `isCurrentKey(clé)` la dit (réactive) ; `write(clé, valeur)` pose la réponse d'une **écriture** si c'est
 * la clé courante, et périme alors toute lecture en vol : un `GET` servi avant le COMMIT d'un `PUT` rendrait les
 * anciennes valeurs, et les poserait par-dessus les nouvelles. Un composable à clé n'en garde pas de copie à lui.
 *
 * `K` est la clé de la lecture : `void` pour une liste, la locale pour le singleton des réglages « À propos ».
 */
export function useAdminLoad<T, K = void>(
  options: AdminLoadOptions<T, K>,
): AdminLoadState & { load: (key: K) => Promise<void>; write: (key: K, value: T) => boolean; isCurrentKey: (key: K) => boolean } {
  const isLoading = ref(true)
  const hasError = ref(false)
  const isLoaded = ref(false)
  const requestGuard = createStaleRequestGuard()
  // La clé de la valeur détenue (dans une boîte : `void` et `undefined` sont des clés valables), ou `null`.
  let held: { key: K } | null = null
  // La lecture qu'une session finie a interrompue, à relire au retour ; `null` dès qu'une lecture a abouti.
  let interrupted: { key: K } | null = null
  // La dernière clé demandée (dans une boîte, comme `held`). Réactive : `isCurrentKey` se lit dans un `computed`.
  const requested = shallowRef<{ key: K } | null>(null)

  const load = async (key: K): Promise<void> => {
    const token = requestGuard.begin()
    requested.value = { key }
    isLoading.value = true
    hasError.value = false
    // Une lecture plus récente remplace l'interrompue : c'est elle, pas l'ancienne, qui sera relue au retour.
    interrupted = null

    if (null !== held && !Object.is(held.key, key)) {
      options.clear?.()
      held = null
      isLoaded.value = false
    }

    try {
      const value = await options.read(key)
      if (!requestGuard.isCurrent(token)) return
      options.apply(value)
      // Après `apply` : un observateur synchrone d'`isLoaded` trouve déjà la valeur posée et recopiée.
      held = { key }
      interrupted = null
      isLoaded.value = true
    } catch (error) {
      if (requestGuard.isCurrent(token)) {
        if (error instanceof SessionEndedError) {
          interrupted = { key }
        } else if (!(error instanceof AccessForbiddenError)) {
          hasError.value = true
        }
      }
    } finally {
      if (requestGuard.isCurrent(token)) isLoading.value = false
    }
  }

  const isCurrentKey = (key: K): boolean => null !== requested.value && Object.is(requested.value.key, key)

  const write = (key: K, value: T): boolean => {
    if (!isCurrentKey(key)) return false

    // Périme la lecture en vol de cette clé (son `finally` ne baissera plus `isLoading`, d'où la ligne qui suit).
    requestGuard.begin()
    options.apply(value)
    held = { key }
    interrupted = null
    hasError.value = false
    isLoading.value = false
    isLoaded.value = true

    return true
  }

  onSessionRestored(() => {
    if (null === interrupted) return
    const { key } = interrupted
    interrupted = null
    void load(key)
  })

  return { isLoading, hasError, isLoaded, load, write, isCurrentKey }
}
