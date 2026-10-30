import { computed, inject, type ComputedRef, type InjectionKey } from 'vue'
import type { AuthRepository } from '../../domain/auth/repositories/AuthRepository'
import type { AuthenticatedUser } from '../../domain/auth/entities/AuthenticatedUser'
import { ANONYMOUS_SESSION, type AccessTier, type AuthSession } from '../../domain/auth/entities/AuthSession'
import { ROLE_SUPER } from '../../domain/auth/entities/Role'
import { hasRole } from '../../domain/auth/services/hasRole'
import { sessionForUser } from '../../domain/auth/services/sessionForUser'
import { markBaseAccessExpired, markBaseAccessGranted, applySession, authState, beginCheck, forgetLastAccount, markFirstCheckDone, waitForAuthCheck } from './sessionState'
import { recallBaseAccessExpiry, scheduleBaseAccessExpiry } from './baseAccessExpiry'
import { advanceGeneration, isCurrentGeneration, sessionStamp } from './sessionGeneration'
import { onAccountSwitched, onSessionRestored } from './sessionEvents'
import { applyLiveSession, canRestore, isReturnOfAccount, markSessionExpired, restoreSession } from './sessionLifecycle'
import { createSingleFlight } from './singleFlight'

/**
 * `useAuth.ts` est la façade de la session (#748, R25) : le composable, les deux relectures de `/api/me` mutualisées,
 * et les **réexports** de tout ce que les autres fichiers importent d'ici — aucun site d'import n'a changé au
 * découpage. Où vit le reste : `sessionState.ts` (l'état et le palier), `baseAccessExpiry.ts` (la minuterie de
 * l'accès de base), `sessionGeneration.ts` (la génération), `sessionEvents.ts` (les deux signaux),
 * `sessionLifecycle.ts` (la fin et le retour).
 */
export {
  ROLE_SUPER,
  authState,
  waitForAuthCheck,
  markBaseAccessGranted,
  markBaseAccessExpired,
  sessionStamp,
  isCurrentGeneration,
  markSessionExpired,
  onSessionRestored,
  onAccountSwitched,
}

export const AUTH_REPOSITORY: InjectionKey<AuthRepository> = Symbol('AuthRepository')

/** La relecture de session finie en vol, partagée par tous les appelants de `useAuth()` : au plus une à la fois. */
const endedSessionRecheck = createSingleFlight<void>()

/**
 * La relecture du palier après un refus réel en vol (#748, R15), partagée elle aussi : une lecture et une révocation
 * refusées ensemble, ou deux pages, n'envoient qu'un `/api/me`.
 */
const refusalReread = createSingleFlight<RefusalReread>()

/** Réservée aux tests (`resetAuthStateForTests`), jamais appelée par `src/` : plus aucune relecture en vol. */
export function resetSessionRereadsForTests(): void {
  endedSessionRecheck.clear()
  refusalReread.clear()
}

/**
 * Ce que dit la relecture du palier après un refus réel (`rereadAfterRefusal`) :
 * - `session-held`  : la session tient (le palier a été relu, ou la relecture a échoué, ou sa réponse est d'une
 *                     session révolue) : le refus reste un refus réel ;
 * - `session-ended` : `/api/me` répond « anonyme » (401) : la session est finie, la page doit le dire.
 */
export type RefusalReread = 'session-held' | 'session-ended'

export interface UseAuthResult {
  user: ComputedRef<AuthenticatedUser | null>
  /** Palier courant (ADR 0003 D1) — la source de vérité pour l'en-tête. */
  tier: ComputedRef<AccessTier>
  /** Vrai dès qu'un jeton valide existe, palier de base compris. */
  isAuthenticated: ComputedRef<boolean>
  isChecking: ComputedRef<boolean>
  /**
   * Vrai depuis qu'un refus du serveur (markSessionExpired) a fermé la session et
   * jusqu'au retour d'un compte : le bandeau du backoffice s'appuie dessus.
   */
  hasSessionEnded: ComputedRef<boolean>
  /**
   * Augmenté de un par chaque fin **acceptée** (`markSessionExpired`, voir la génération), y compris quand la session
   * est déjà finie : un état ne change pas au second refus, ce compteur si. Une réponse partie sous une génération
   * révolue (une lecture parallèle déjà annoncée, une session rétablie depuis) ne l'augmente pas. Le bandeau en fait
   * la `key` du `<span>` de son message, que Vue remplace donc à chaque refus accepté — c'est ce remplacement que les
   * lecteurs d'écran relisent, l'alerte (et le lien qu'elle porte) restant le même élément (#748, S7, R14, R19).
   */
  sessionEndedCount: ComputedRef<number>
  /** ROLE_TRUSTED (ou ROLE_SUPER) : le seul palier qui ouvre le CV et /api/me. */
  isTrusted: ComputedRef<boolean>
  isSuperAdmin: ComputedRef<boolean>
  login: (username: string, password: string) => Promise<void>
  logout: () => Promise<void>
  /**
   * Relit `/api/me` quand la session est finie, en silence (`isChecking` n'est pas touché). Sans effet hors
   * session finie. Une seule relecture en vol : un second appel rend la même promesse. Un compte retrouvé
   * **et** un jeton d'écriture (`AuthRepository.hasWriteToken()`) rétablissent la session — sauf si la réponse est
   * d'une génération révolue (un refus est arrivé depuis l'envoi) ; sinon l'état ne
   * change pas — un compte sans `XSRF-TOKEN` prendrait un 403 à la première écriture, la session n'est pas
   * rétablie. Un rejet de `/api/me` (réseau) est avalé : la session reste finie, la personne recommencera au
   * prochain retour. Branchée une fois sur le `focus` de la fenêtre par `useSessionRecheckOnFocus` (AppLayout).
   */
  recheckEndedSession: () => Promise<void>
  /**
   * Relit `/api/me` après un **refus réel** (un 403 qui ne finit pas la session), en silence (`isChecking` n'est pas
   * touché), **une seule fois** : une relecture en vol est partagée par tous les appelants (une lecture et une
   * révocation refusées ensemble n'envoient qu'un `/api/me`). La génération de la session est lue à l'envoi : une
   * réponse d'une session révolue ne change rien.
   * - le compte, ou le palier de base : la session appliquée (l'en-tête reprend le palier que le serveur reconnaît),
   *   `'session-held'` ;
   * - « anonyme » (401) : la session est **finie** (`markSessionExpired` à la génération du départ, sans réannoncer
   *   une fin déjà dite), `'session-ended'` — l'appelant passe son refus de `forbidden` à « session finie » ;
   * - un rejet (réseau) est avalé : `'session-held'`, le refus reste ce qu'il était.
   */
  rereadAfterRefusal: () => Promise<RefusalReread>
  /**
   * **La** suite d'un refus réel, écrite une fois (#748, R27, St3-7) : relit le palier (`rereadAfterRefusal`), sans que
   * l'appelant l'attende — la page montre déjà le refus —, puis, si la relecture dit `'session-ended'` **et** que
   * `isStillRefused()` vaut toujours vrai à l'arrivée de la réponse, appelle `showSessionEnded()`. Le refus affiché est
   * jugé à l'arrivée, pas au départ : une relecture de la page lancée entre-temps l'a peut-être déjà remis à zéro, et
   * l'écraser par « session finie » dirait un état qui n'est plus le sien. Session tenue, relecture en échec : rien.
   */
  followRefusal: (isStillRefused: () => boolean, showSessionEnded: () => void) => void
  /**
   * Abonne `callback` au **retour** de la session : il est appelé une fois, de façon synchrone, à chaque descente
   * de `sessionEnded` (vrai → faux) où **le même compte** revient (même identifiant, aucun rôle perdu, #748, R26) — par
   * la relecture au retour sur l'onglet. Ni pour un autre compte (`onAccountSwitched`), ni pour une connexion dans l'onglet (la page
   * de connexion navigue elle-même, #748, R18). Jamais à la fin de la session, ni quand rien n'était fini. À appeler dans un scope (le `setup` d'un
   * composant, un `effectScope`) : l'abonnement est retiré à `onScopeDispose`. C'est **le** signal de retour :
   * une page qui dépend du retour (relire ce qu'une session finie a interrompu) s'y abonne, elle ne surveille
   * plus `authState.sessionEnded` elle-même.
   */
  onSessionRestored: (callback: () => void) => void
  /**
   * Abonne `callback` au **changement de compte** (#748, R18, R26) : la session a fini et c'est **un autre compte**, ou
   * le même avec un rôle perdu, ou un compte quand aucun n'était retenu, qui est revenu (par la relecture au retour sur
   * l'onglet, ou `checkAuth()`) ; ou, hors session finie, une relecture (`checkAuth()`, `rereadAfterRefusal()`) trouve
   * **un autre identifiant** que celui du dernier compte affiché. La session est déjà appliquée quand `callback` est appelé ; `onSessionRestored` n'est pas appelé (la page ouverte n'est pas
   * « rétablie » : ce n'est plus la page de ce compte). Jamais pour une connexion dans l'onglet. Même mécanique que
   * `onSessionRestored` : un scope, retiré à `onScopeDispose`. Seul `AppLayout` s'y abonne.
   */
  onAccountSwitched: (callback: () => void) => void
  checkAuth: () => Promise<void>
}

export function useAuth(): UseAuthResult {
  const repository = inject(AUTH_REPOSITORY)

  if (!repository) {
    throw new Error(
      "AuthRepository n'a pas été fourni. Vérifiez que app.provide(AUTH_REPOSITORY, ...) est bien appelé dans main.ts.",
    )
  }

  const login = async (username: string, password: string): Promise<void> => {
    const session = sessionForUser(await repository.login(username, password))
    // Une connexion est le geste de la personne : elle vaut quelle que soit la génération (rien n'est parti avant
    // elle qui la contredise) et **avance toujours la génération** — un `/api/me` parti avant elle est périmé (R26,
    // CR3-4). En session finie elle rétablit **toujours** : le backend pose `XSRF-TOKEN` à la connexion, et s'il manque
    // la première écriture prendra le 403 et refinira la session — état cohérent (R26, CR3-9). Elle ne rappelle aucun
    // abonné (#748, R18) : la page de connexion navigue elle-même, rien n'est « rétabli » ni rejoué ici.
    if (isReturnOfAccount(session)) {
      restoreSession(session, false)
    } else {
      advanceGeneration()
      applySession(session)
    }
  }

  const logout = async (): Promise<void> => {
    // Décidée dans l'onglet, la déconnexion périme dès l'envoi tout `/api/me` parti avant elle : sa réponse ne
    // « reconnecte » pas l'en-tête pendant que le serveur répond (R26, CR3-4).
    advanceGeneration()
    try {
      await repository.logout()
    } finally {
      // Même si le serveur refuse (un XSRF-TOKEN signé sous une clé qui a
      // changé répond 403, issue #455) : rester « connecté » fermerait la page
      // de login, seule sortie — un nouveau login remplace les deux cookies.
      // L'erreur remonte, pour que l'appelant y mène plutôt qu'à l'accueil ;
      // un rechargement relit le palier réel sur /api/me.
      applySession(ANONYMOUS_SESSION)
      // La personne a quitté ce compte : plus aucun n'est « le dernier affiché ». Et un `/api/me` parti pendant
      // l'envoi est lui aussi périmé.
      forgetLastAccount()
      advanceGeneration()
    }
  }

  /**
   * Applique une session lue sur `/api/me` (`checkAuth`, `rereadAfterRefusal`). Rend `false`, sans rien changer, quand
   * la réponse est révolue (une session décidée dans l'onglet, une fin ou un retour est arrivé depuis l'envoi). En
   * session finie (Sp3-3), seul un compte qui `canRestore` (un jeton d'écriture : sans lui sa première écriture
   * prendrait le 403 du CSRF) change quelque chose, par la descente unique ; le palier de base, l'anonyme et un compte
   * sans jeton ne posent rien — l'en-tête ne bouge pas (F5, C). Hors session finie, `applyLiveSession` applique et
   * signale un autre compte (CR3-6).
   */
  const applyReadSession = (session: AuthSession, sentAt: number): boolean => {
    if (!isCurrentGeneration(sentAt)) {
      return false
    }
    if (authState.sessionEnded) {
      if (!canRestore(session, () => repository.hasWriteToken())) {
        return false
      }
      restoreSession(session, true)
    } else {
      applyLiveSession(session)
    }

    return true
  }

  /**
   * La vérification de la session au chargement (`main.ts`) et à chaque relecture hors session finie. **Elle ne rejette
   * jamais** (#748, R49, étape 3b) : un rejet de `/api/me` — le réseau, ou l'abandon après `ME_TIMEOUT_MS` — ne dit
   * rien du palier ; la première vérification finit donc comme pour un anonyme (`isChecking` retombe, l'état n'est
   * pas touché, `waitForAuthCheck()` se résout, la route `login` et le garde s'ouvrent). `main.ts` lance `checkAuth()`
   * sans l'attendre ni l'attraper : sans ce `catch`, le rejet y serait un rejet non géré (le `finally` relâche
   * `isChecking`, mais n'absorbe rien).
   */
  const checkAuth = async (): Promise<void> => {
    // Silencieuse après la première vérification : voir `hasCheckedOnce`.
    beginCheck()
    try {
      // Lue avant applySession(), qui efface la mémoire : si le serveur
      // confirme le palier de base et qu'une échéance future est connue, la
      // minuterie reprend là où le rechargement l'avait interrompue.
      const rememberedExpiry = recallBaseAccessExpiry()
      // La génération est lue à l'envoi : une réponse arrivée après une fin ou un retour est d'une session révolue.
      const sentAt = sessionStamp()
      const session = await repository.me()
      if (!applyReadSession(session, sentAt)) {
        return
      }
      if ('base' === session.tier && null === session.user && null !== rememberedExpiry && rememberedExpiry.getTime() > Date.now()) {
        scheduleBaseAccessExpiry(rememberedExpiry, markBaseAccessExpired)
      }
    } catch {
      // Le réseau ne répond pas, ou `me()` a abandonné : le palier reste ce qu'il était (anonyme au chargement),
      // la personne le saura à sa prochaine requête.
    } finally {
      markFirstCheckDone()
    }
  }

  const recheckEndedSession = (): Promise<void> => {
    if (!authState.sessionEnded) {
      return Promise.resolve()
    }

    return endedSessionRecheck.run(async () => {
      try {
        const sentAt = sessionStamp()
        const session = await repository.me()
        // Le compte seul ne suffit pas : sans jeton d'écriture la première écriture serait refusée (403 sans CSRF).
        // Et une réponse partie avant un nouveau refus est d'avant lui : elle ne rétablit pas ce qui vient de finir.
        if (isCurrentGeneration(sentAt) && canRestore(session, () => repository.hasWriteToken())) {
          restoreSession(session, true)
        }
      } catch {
        // Le réseau ne répond pas : la session reste finie, rien n'est annoncé.
      }
    })
  }

  const rereadAfterRefusal = (): Promise<RefusalReread> =>
    refusalReread.run(async (): Promise<RefusalReread> => {
      try {
        const sentAt = sessionStamp()
        const session = await repository.me()
        if ('anonymous' === session.tier) {
          // `/api/me` ne rejette pas sur un 401 : il rend l'anonyme. Le jeton ne vaut plus rien, la session est finie.
          // Déjà finie sous la génération du départ, elle est dite : on ne la réannonce pas (la relecture n'est pas
          // un geste de la personne). Partie sous une génération révolue, `markSessionExpired` ne change rien.
          if (authState.sessionEnded && isCurrentGeneration(sentAt)) {
            return 'session-ended'
          }

          return markSessionExpired(sentAt) ? 'session-ended' : 'session-held'
        }
        applyReadSession(session, sentAt)

        return 'session-held'
      } catch {
        // Le réseau ne répond pas : le refus reste ce qu'il était, la page n'en dit pas plus.
        return 'session-held'
      }
    })

  const followRefusal = (isStillRefused: () => boolean, showSessionEnded: () => void): void => {
    void rereadAfterRefusal().then((reread) => {
      if ('session-ended' === reread && isStillRefused()) {
        showSessionEnded()
      }
    })
  }

  return {
    user: computed(() => authState.user),
    tier: computed(() => authState.tier),
    isAuthenticated: computed(() => 'anonymous' !== authState.tier),
    isChecking: computed(() => authState.isChecking),
    hasSessionEnded: computed(() => authState.sessionEnded),
    sessionEndedCount: computed(() => authState.sessionEndedCount),
    isTrusted: computed(() => 'trusted' === authState.tier),
    isSuperAdmin: computed(() => hasRole(authState.user, ROLE_SUPER)),
    login,
    logout,
    recheckEndedSession,
    rereadAfterRefusal,
    followRefusal,
    onSessionRestored,
    onAccountSwitched,
    checkAuth,
  }
}
