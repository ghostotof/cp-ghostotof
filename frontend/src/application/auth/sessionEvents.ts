import { onScopeDispose } from 'vue'

/**
 * Les deux signaux de la session (#748, R13, R18) : le **retour** (le même compte est revenu, la page ouverte est la
 * sienne) et le **changement de compte** (un autre est revenu, la page ouverte n'est pas la sienne). Ce module ne
 * connaît que les abonnés ; c'est `sessionLifecycle.ts` qui décide lequel appeler.
 *
 * Des `Set` de module comme l'état : le retour est un événement de la session, pas d'un composant. Chaque abonné se
 * retire à la fin de son scope, donc une page démontée n'est plus appelée.
 */

/** Les abonnés au retour de la session (`useAuth().onSessionRestored`), de tous les scopes vivants. */
const sessionRestoredCallbacks = new Set<() => void>()

/**
 * Les abonnés au **changement de compte** (`useAuth().onAccountSwitched`, #748, R18) : un autre compte que celui dont
 * la session a fini est revenu. Même mécanique que `sessionRestoredCallbacks`, mais un événement distinct : la page
 * ouverte n'est pas celle de ce compte, elle n'est pas « rétablie » — `AppLayout` rejoue la navigation.
 */
const accountSwitchedCallbacks = new Set<() => void>()

/**
 * Inscrit `callback` dans `callbacks` et le retire à la fin du scope courant. Une fonction propre à cet abonnement :
 * le même `callback` pris par deux scopes ne se retire pas de l'un pour l'autre.
 */
function subscribe(callbacks: Set<() => void>, callback: () => void): void {
  const subscription = (): void => callback()
  callbacks.add(subscription)
  onScopeDispose(() => callbacks.delete(subscription))
}

/**
 * Appelle chaque abonné. La copie du `Set` protège l'itération d'un abonné qui se retirerait en route. Un abonné qui
 * lève n'arrête ni les suivants ni l'issue de la connexion ou de la relecture qui a rétabli la session : l'erreur
 * est rapportée, avec le nom du signal (`label` : « au retour de la session », « au changement de compte »).
 */
function notify(callbacks: ReadonlySet<() => void>, label: string): void {
  for (const callback of [...callbacks]) {
    try {
      callback()
    } catch (caught) {
      console.error(`Un abonné ${label} a levé une erreur.`, caught)
    }
  }
}

/**
 * Abonne `callback` au **retour** de la session : voir `UseAuthResult.onSessionRestored`, dont c'est la même
 * fonction. Exportée à part, comme `authState` et `markSessionExpired`, pour les composables qui n'ont pas
 * besoin du dépôt d'authentification (`useAdminLoad` : treize composables d'administration, dont aucun ne
 * fournit `AUTH_REPOSITORY` dans ses tests) — `useAuth()` le leur demanderait pour rien.
 */
export function onSessionRestored(callback: () => void): void {
  subscribe(sessionRestoredCallbacks, callback)
}

/**
 * Abonne `callback` au **changement de compte** : voir `UseAuthResult.onAccountSwitched`, dont c'est la même fonction.
 */
export function onAccountSwitched(callback: () => void): void {
  subscribe(accountSwitchedCallbacks, callback)
}

/** Appelle les abonnés au retour (le même compte est revenu). */
export function notifySessionRestored(): void {
  notify(sessionRestoredCallbacks, 'au retour de la session')
}

/** Appelle les abonnés au changement de compte (un autre compte est revenu). */
export function notifyAccountSwitched(): void {
  notify(accountSwitchedCallbacks, 'au changement de compte')
}

/** Réservée aux tests (`resetAuthStateForTests`), jamais appelée par `src/` : plus aucun abonné. */
export function resetSessionEventsForTests(): void {
  sessionRestoredCallbacks.clear()
  accountSwitchedCallbacks.clear()
}
