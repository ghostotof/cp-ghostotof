import { computed, inject, onScopeDispose, reactive, readonly, watch, type ComputedRef, type InjectionKey } from 'vue'
import type { AuthRepository } from '../../domain/auth/repositories/AuthRepository'
import type { AuthenticatedUser } from '../../domain/auth/entities/AuthenticatedUser'
import { ANONYMOUS_SESSION, BASE_ACCESS_SESSION, type AccessTier, type AuthSession } from '../../domain/auth/entities/AuthSession'
import { ROLE_SUPER } from '../../domain/auth/entities/Role'
import { hasRole } from '../../domain/auth/services/hasRole'
import { sessionForUser } from '../../domain/auth/services/sessionForUser'

export { ROLE_SUPER }

export const AUTH_REPOSITORY: InjectionKey<AuthRepository> = Symbol('AuthRepository')

/**
 * Contrairement à usePortfolioContent (purement dérivé, sans état propre),
 * la session courante doit être partagée entre des composants sans lien
 * parent/enfant direct (AppHeader, LoginPage, CaseStudiesPage) et survivre à
 * une navigation : c'est donc un état singleton au niveau du module, créé une
 * seule fois, plutôt que recréé à chaque appel de useAuth().
 *
 * `tier`/`user` sont à plat (plutôt qu'un objet `session` imbriqué) pour que
 * les consommateurs existants — le garde de routeur lit `authState.user` —
 * n'aient pas à changer.
 */
const state = reactive<{ tier: AccessTier; user: AuthenticatedUser | null; isChecking: boolean; sessionEnded: boolean; sessionEndedCount: number }>({
  tier: ANONYMOUS_SESSION.tier,
  user: ANONYMOUS_SESSION.user,
  isChecking: true,
  // Le serveur a refusé la session pendant qu'une page était ouverte (voir
  // markSessionExpired). Distinct de « anonyme » : c'est ce qui permet au
  // backoffice d'annoncer la fin de session sans quitter la page (#650, S2).
  sessionEnded: false,
  // Nombre de refus qui ont fini la session depuis le chargement : `sessionEnded` est un état, idempotent, il
  // ne dit pas qu'un refus de plus vient d'arriver ; ce compteur le dit (le bandeau se réannonce, #748 S7).
  sessionEndedCount: 0,
})

/**
 * Vrai dès que la première vérification (celle de `main.ts`) a fini, réussie ou non. Avant elle, `isChecking`
 * cache le groupe de droite de l'en-tête et fait attendre le garde de route (`waitForAuthCheck`) : c'est son
 * seul rôle. Après elle, une relecture de `/api/me` (le retour sur l'onglet, un refus réel) est **silencieuse** :
 * y toucher ferait disparaître puis réapparaître l'en-tête à chaque retour (#748, R8).
 */
let hasCheckedOnce = false

/** La relecture de session finie en vol, partagée par tous les appelants de `useAuth()` : au plus une à la fois. */
let endedSessionRecheck: Promise<void> | null = null

/**
 * La relecture du palier après un refus réel en vol (#748, R15), partagée elle aussi : une lecture et une révocation
 * refusées ensemble, ou deux pages, n'envoient qu'un `/api/me`.
 */
let refusalReread: Promise<RefusalReread> | null = null

/**
 * La génération de la session (#748, R14) : un compteur de module, avancé à chaque **fin** acceptée
 * (`markSessionExpired`) et à chaque **retour** (la descente de `sessionEnded`). Un appelant la lit à l'envoi
 * (`sessionStamp()`) et la rend avec le verdict (`markSessionExpired(sentAt)`, la relecture de `/api/me`) : ce qui
 * est parti sous une génération révolue est un verdict d'une session qui n'est plus la courante, et ne la change pas,
 * ni pour la finir ni pour la rétablir. Un seul nombre règle les deux sens, et les lectures parallèles du chargement
 * d'une page (parties sous la même génération) n'annoncent la fin qu'une fois.
 */
let generation = 0

/**
 * Les abonnés au retour de la session (`useAuth().onSessionRestored`), de tous les scopes vivants. Un `Set` de
 * module comme l'état : le retour est un événement de la session, pas d'un composant. Chaque abonné se retire à
 * la fin de son scope, donc une page démontée n'est plus appelée (#748, R13).
 */
const sessionRestoredCallbacks = new Set<() => void>()

/**
 * Les abonnés au **changement de compte** (`useAuth().onAccountSwitched`, #748, R18) : un autre compte que celui dont
 * la session a fini est revenu. Même mécanique que `sessionRestoredCallbacks`, mais un événement distinct : la page
 * ouverte n'est pas celle de ce compte, elle n'est pas « rétablie » — `AppLayout` rejoue la navigation.
 */
const accountSwitchedCallbacks = new Set<() => void>()

/**
 * Le compte dont la session a fini (#748, R18) : l'identifiant du compte affiché à la **première** fin acceptée
 * (`markSessionExpired` repasse l'en-tête à anonyme, il faut le lire avant), `null` quand aucun compte n'était
 * affiché (palier de base, anonyme). Ce qui revient est « le même » si l'identifiant est celui-ci, ou s'il n'y en a
 * aucun de retenu ; sinon la page ouverte est celle d'un autre compte. Oublié au retour.
 */
let endedAccount: string | null = null

/**
 * Échéance du jeton D6, mémorisée pour survivre à un rechargement de page :
 * après un F5, checkAuth() ne voit qu'un 403 sur /api/me (palier de base),
 * sans savoir jusqu'à quand. Convenance locale, jamais une source de vérité :
 * un localStorage indisponible ou vide rend simplement le badge un peu
 * moins précis (comportement d'avant).
 */
const BASE_ACCESS_EXPIRY_STORAGE_KEY = 'baseAccessExpiresAt'

/** Plafond de setTimeout (2^31 − 1 ms) : au-delà, le navigateur déclenche immédiatement. */
const MAX_TIMEOUT_MS = 2_147_483_647

let baseAccessExpiryTimer: ReturnType<typeof setTimeout> | null = null

/**
 * L'échéance de la minuterie armée (`null` : aucune). Gardée en plus de la minuterie parce qu'un onglet en arrière-plan
 * ou une machine en veille retarde `setTimeout` : au retour (`focus`, `visibilitychange`), c'est l'horloge, pas la
 * minuterie, qui dit si l'échéance est passée (#748, R21b).
 */
let baseAccessExpiresAt: Date | null = null

/**
 * Le dépôt d'authentification, retenu au dernier `useAuth()` : la minuterie de l'accès de base vit au niveau du
 * module, hors de tout composant, où `inject()` n'est pas utilisable, et elle doit pourtant appeler `logout()` à
 * l'échéance (#748, R21b). `main.ts` appelle `useAuth()` au démarrage, donc avant la première obtention. Sans dépôt
 * connu (un test qui n'en fournit pas), l'échéance retombe à anonyme sans appel réseau, comme avant.
 */
let knownRepository: AuthRepository | null = null

/**
 * Pose le palier et le compte. **Ne touche jamais à `sessionEnded`** : la fin est l'affaire de
 * `markSessionExpired`, le retour de `restoreSession`, qui seuls jugent la génération et le jeton d'écriture.
 */
function applySession(session: AuthSession): void {
  state.tier = session.tier
  state.user = session.user
  // Tout changement de session rend la minuterie précédente caduque : un
  // login remplace le cookie D6 par celui du compte, un logout l'efface.
  clearBaseAccessExpiry()
}

/**
 * **La** descente de `sessionEnded` : un compte est revenu avec son jeton d'écriture (l'appelant l'a vérifié, et la
 * génération, sauf pour une connexion). Applique la session, avance la génération, puis — une fois, de façon
 * synchrone, l'état posé : les abonnés lisent la session revenue — appelle :
 * - `onSessionRestored` si c'est **le même compte** que celui dont la session a fini (ou si aucun n'était affiché) :
 *   la page ouverte est la sienne, elle reprend en place ;
 * - `onAccountSwitched` si c'est un autre : la session est appliquée, mais la page ouverte n'est pas la sienne (#748,
 *   R18) ; seul `AppLayout` y réagit, en rejouant la navigation.
 * Une **connexion** (`announce` faux) ne rappelle personne : la page de connexion navigue elle-même.
 */
function restoreSession(session: AuthSession, announce: boolean): void {
  const endedFor = endedAccount
  endedAccount = null
  applySession(session)
  state.sessionEnded = false
  generation += 1
  if (!announce) {
    return
  }

  const isSameAccount = null === endedFor || endedFor === session.user?.username
  notify(isSameAccount ? sessionRestoredCallbacks : accountSwitchedCallbacks)
}

/**
 * Appelle chaque abonné. La copie du `Set` protège l'itération d'un abonné qui se retirerait en route. Un abonné qui
 * lève n'arrête ni les suivants ni l'issue de la connexion ou de la relecture qui a rétabli la session : l'erreur
 * est rapportée.
 */
function notify(callbacks: ReadonlySet<() => void>): void {
  for (const callback of [...callbacks]) {
    try {
      callback()
    } catch (caught) {
      console.error('Un abonné au retour de la session a levé une erreur.', caught)
    }
  }
}

function clearBaseAccessExpiry(): void {
  if (null !== baseAccessExpiryTimer) {
    clearTimeout(baseAccessExpiryTimer)
    baseAccessExpiryTimer = null
  }
  if (null !== baseAccessExpiresAt) {
    baseAccessExpiresAt = null
    window.removeEventListener('focus', expireBaseAccessIfDue)
    document.removeEventListener('visibilitychange', expireBaseAccessIfDue)
  }
  try {
    localStorage.removeItem(BASE_ACCESS_EXPIRY_STORAGE_KEY)
  } catch {
    // Stockage indisponible (navigation privée, données bloquées) : sans conséquence.
  }
}

function scheduleBaseAccessExpiry(expiresAt: Date): void {
  clearBaseAccessExpiry()
  try {
    localStorage.setItem(BASE_ACCESS_EXPIRY_STORAGE_KEY, expiresAt.toISOString())
  } catch {
    // Idem : le badge survivra moins bien à un rechargement, rien de plus.
  }
  const delay = Math.min(Math.max(expiresAt.getTime() - Date.now(), 0), MAX_TIMEOUT_MS)
  baseAccessExpiresAt = expiresAt
  // La minuterie est l'unique échéance ; le retour sur l'onglet n'est qu'un second déclencheur de la même sortie.
  baseAccessExpiryTimer = setTimeout(endBaseAccessAtExpiry, delay)
  window.addEventListener('focus', expireBaseAccessIfDue)
  document.addEventListener('visibilitychange', expireBaseAccessIfDue)
}

/** Le retour sur l'onglet (ou sa réapparition) : l'échéance est-elle passée pendant que la minuterie dormait ? */
function expireBaseAccessIfDue(): void {
  if (null !== baseAccessExpiresAt && baseAccessExpiresAt.getTime() <= Date.now()) {
    endBaseAccessAtExpiry()
  }
}

/**
 * L'accès de base est arrivé à son échéance (#748, R21b, décision de l'auteur du 2026-10-29) : le frontend la connaît
 * (`expiresAt`), pas le login, et en sortant **avant** que le jeton ne soit refusé il évite le 401 qui effacerait les
 * cookies au milieu d'une page publique (R21). L'état repasse à anonyme d'abord (rien n'attend le réseau, et un échec
 * du logout le laisse tel), puis `POST /api/logout` expire les deux cookies — avec son jeton CSRF, par le dépôt.
 * Ce n'est **pas** une session finie à annoncer : ni `markSessionExpired`, ni bandeau, ni génération. Le `.catch` est
 * la limite acceptée : un logout qui n'arrive pas laisse les cookies, que le 401 suivant effacera. Sans effet si le
 * palier n'est plus celui de l'accès de base sans compte (un compte n'est jamais déconnecté par ce chemin).
 */
function endBaseAccessAtExpiry(): void {
  const isBaseAccess = 'base' === state.tier && null === state.user
  markBaseAccessExpired()
  clearBaseAccessExpiry()
  if (isBaseAccess) {
    void knownRepository?.logout().catch(() => undefined)
  }
}

function recallBaseAccessExpiry(): Date | null {
  try {
    const stored = localStorage.getItem(BASE_ACCESS_EXPIRY_STORAGE_KEY)
    if (null === stored) {
      return null
    }
    const expiresAt = new Date(stored)

    return Number.isNaN(expiresAt.getTime()) ? null : expiresAt
  } catch {
    return null
  }
}

/**
 * Lecture seule de l'état d'auth, exposée à part de useAuth() : un garde de
 * navigation (presentation/router/index.ts) ou un `watch` de page s'exécute
 * hors contexte de composant, où inject() n'est pas utilisable.
 */
export const authState = readonly(state)

/**
 * Au premier chargement (rechargement de page, navigation directe vers une
 * URL protégée), le garde de route (presentation/router/index.ts) peut
 * s'exécuter AVANT que le checkAuth() lancé par main.ts n'ait résolu :
 * authState.isChecking vaut alors encore `true`. Sans attendre sa résolution,
 * un utilisateur pourtant valablement connecté (cookie httpOnly toujours
 * valide) se ferait rediriger à tort vers /login.
 */
export async function waitForAuthCheck(): Promise<void> {
  if (!state.isChecking) {
    return
  }
  await new Promise<void>((resolve) => {
    const stopWatching = watch(
      () => state.isChecking,
      (isChecking) => {
        if (!isChecking) {
          stopWatching()
          resolve()
        }
      },
    )
  })
}

/**
 * À appeler une fois POST /api/account/base-access a réussi (ADR 0003 D6) :
 * le cookie BEARER vient d'être posé par le navigateur, invisible en JS, et
 * relire /api/me pour le constater coûterait un aller-retour pour apprendre
 * ce qu'on sait déjà. Exposée hors composant (comme authState) parce que
 * l'appelant, useBaseAccess, ne doit pas dépendre de AUTH_REPOSITORY.
 */
export function markBaseAccessGranted(expiresAt: Date | null = null): void {
  applySession(BASE_ACCESS_SESSION)
  if (null !== expiresAt) {
    scheduleBaseAccessExpiry(expiresAt)
  }
}

/**
 * Le jeton D6 n'existe plus : son échéance est passée, ou le backend vient
 * de répondre 401 à un contenu du palier de base (useCaseStudies,
 * useAnonymousCv). Sans ce signal, l'en-tête afficherait « Accès de base »
 * alors que la page en dessous demande déjà de l'obtenir. Ne touche qu'au
 * palier de base sans compte : un compte identifié a sa propre session, et
 * ce n'est pas à un timer local de la révoquer.
 */
export function markBaseAccessExpired(): void {
  if ('base' === state.tier && null === state.user) {
    applySession(ANONYMOUS_SESSION)
  }
}

/**
 * La génération courante de la session : à lire **à l'envoi** d'une requête, pour la rendre avec son verdict
 * (`markSessionExpired(sentAt)`). Voir `generation`.
 */
export function sessionStamp(): number {
  return generation
}

/**
 * Un appel du palier de confiance (l'assistant, ROLE_TRUSTED) ou du backoffice a reçu un verdict « session finie »
 * (un 401, ou un 403 à une écriture sans jeton CSRF) : le jeton ne vaut plus ce palier (expiré, révoqué, ou compte
 * rétrogradé). Contrairement à markBaseAccessExpired, repasse à anonyme sans condition : la page qui l'appelle ne
 * sert que ce palier, et garder un en-tête « connecté » au-dessus d'une page qui vient de refuser l'accès serait mentir.
 *
 * `sentAt` est la génération lue à l'envoi (`sessionStamp()`), et le rendu dit si la session **est finie** :
 * - sans `sentAt`, ou sous la génération courante : la fin est acceptée (l'en-tête devient anonyme, `sessionEnded`
 *   monte, `sessionEndedCount` augmente — un refus pendant une session déjà finie réannonce), la génération avance ;
 *   rend `true` ;
 * - sous une génération révolue : rien ne change. Rend `true` si la session est finie (le verdict tient, il a déjà
 *   été annoncé : les lectures parallèles du chargement d'une page ne le réannoncent pas), `false` si elle a été
 *   rétablie depuis l'envoi — la réponse est celle d'une session qui n'est plus la courante, l'appelant la traite
 *   comme un échec ordinaire.
 */
export function markSessionExpired(sentAt?: number): boolean {
  if (undefined !== sentAt && sentAt !== generation) {
    return state.sessionEnded
  }

  // Le compte dont la session finit est celui de la **première** fin : les suivantes trouvent l'en-tête déjà anonyme.
  if (!state.sessionEnded) {
    endedAccount = state.user?.username ?? null
  }
  applySession(ANONYMOUS_SESSION)
  state.sessionEnded = true
  state.sessionEndedCount += 1
  generation += 1

  return true
}

/**
 * Abonne `callback` au **retour** de la session : voir `UseAuthResult.onSessionRestored`, dont c'est la même
 * fonction. Exportée à part, comme `authState` et `markSessionExpired`, pour les composables qui n'ont pas
 * besoin du dépôt d'authentification (`useAdminLoad` : treize composables d'administration, dont aucun ne
 * fournit `AUTH_REPOSITORY` dans ses tests) — `useAuth()` le leur demanderait pour rien.
 */
export function onSessionRestored(callback: () => void): void {
  // Une fonction propre à cet abonnement : le même `callback` pris par deux scopes ne se retire pas de l'un pour l'autre.
  const subscription = (): void => callback()
  sessionRestoredCallbacks.add(subscription)
  onScopeDispose(() => sessionRestoredCallbacks.delete(subscription))
}

/**
 * Abonne `callback` au **changement de compte** : voir `UseAuthResult.onAccountSwitched`, dont c'est la même fonction.
 */
export function onAccountSwitched(callback: () => void): void {
  const subscription = (): void => callback()
  accountSwitchedCallbacks.add(subscription)
  onScopeDispose(() => accountSwitchedCallbacks.delete(subscription))
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
   * Abonne `callback` au **retour** de la session : il est appelé une fois, de façon synchrone, à chaque descente
   * de `sessionEnded` (vrai → faux) où **le même compte** revient (ou aucun n'était affiché) — par la relecture au
   * retour sur l'onglet. Ni pour un autre compte (`onAccountSwitched`), ni pour une connexion dans l'onglet (la page
   * de connexion navigue elle-même, #748, R18). Jamais à la fin de la session, ni quand rien n'était fini. À appeler dans un scope (le `setup` d'un
   * composant, un `effectScope`) : l'abonnement est retiré à `onScopeDispose`. C'est **le** signal de retour :
   * une page qui dépend du retour (relire ce qu'une session finie a interrompu) s'y abonne, elle ne surveille
   * plus `authState.sessionEnded` elle-même.
   */
  onSessionRestored: (callback: () => void) => void
  /**
   * Abonne `callback` au **changement de compte** (#748, R18) : la session a fini, et c'est **un autre compte** que
   * celui qui était affiché qui est revenu (par la relecture au retour sur l'onglet, ou `checkAuth()`). La session
   * est déjà appliquée quand `callback` est appelé ; `onSessionRestored` n'est pas appelé (la page ouverte n'est pas
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
  knownRepository = repository

  const login = async (username: string, password: string): Promise<void> => {
    const session = sessionForUser(await repository.login(username, password))
    // Une connexion est le geste de la personne : elle vaut quelle que soit la génération (rien n'est parti avant
    // elle qui la contredise), mais comme toute descente elle veut un compte et un jeton d'écriture. Elle ne rappelle
    // aucun abonné (#748, R18) : la page de connexion navigue elle-même, rien n'est « rétabli » ni rejoué ici.
    if (state.sessionEnded && null !== session.user && repository.hasWriteToken()) {
      restoreSession(session, false)
    } else {
      applySession(session)
    }
  }

  const logout = async (): Promise<void> => {
    try {
      await repository.logout()
    } finally {
      // Même si le serveur refuse (un XSRF-TOKEN signé sous une clé qui a
      // changé répond 403, issue #455) : rester « connecté » fermerait la page
      // de login, seule sortie — un nouveau login remplace les deux cookies.
      // L'erreur remonte, pour que l'appelant y mène plutôt qu'à l'accueil ;
      // un rechargement relit le palier réel sur /api/me.
      applySession(ANONYMOUS_SESSION)
    }
  }

  /**
   * Applique une session lue sur `/api/me` (`checkAuth`, `rereadAfterRefusal`). Rend `false`, sans rien changer, quand
   * la réponse est révolue (une fin ou un retour est arrivé depuis l'envoi), ou quand c'est un compte sans jeton
   * d'écriture pendant une session finie : elle n'est pas rétablie (sa première écriture prendrait le 403 du CSRF)
   * et l'en-tête ne bouge pas (F5, C). Un compte pendant une session finie la rétablit, par la descente unique.
   */
  const applyReadSession = (session: AuthSession, sentAt: number): boolean => {
    if (sentAt !== generation || (state.sessionEnded && null !== session.user && !repository.hasWriteToken())) {
      return false
    }
    if (state.sessionEnded && null !== session.user) {
      restoreSession(session, true)
    } else {
      applySession(session)
    }

    return true
  }

  const checkAuth = async (): Promise<void> => {
    // Silencieuse après la première vérification : voir `hasCheckedOnce`.
    if (!hasCheckedOnce) {
      state.isChecking = true
    }
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
        scheduleBaseAccessExpiry(rememberedExpiry)
      }
    } finally {
      hasCheckedOnce = true
      state.isChecking = false
    }
  }

  const recheckEndedSession = (): Promise<void> => {
    if (!state.sessionEnded) {
      return Promise.resolve()
    }
    if (null !== endedSessionRecheck) {
      return endedSessionRecheck
    }

    endedSessionRecheck = (async () => {
      try {
        const sentAt = sessionStamp()
        const session = await repository.me()
        // Le compte seul ne suffit pas : sans jeton d'écriture la première écriture serait refusée (403 sans CSRF).
        // Et une réponse partie avant un nouveau refus est d'avant lui : elle ne rétablit pas ce qui vient de finir.
        if (sentAt === generation && state.sessionEnded && null !== session.user && repository.hasWriteToken()) {
          restoreSession(session, true)
        }
      } catch {
        // Le réseau ne répond pas : la session reste finie, rien n'est annoncé.
      } finally {
        endedSessionRecheck = null
      }
    })()

    return endedSessionRecheck
  }

  const rereadAfterRefusal = (): Promise<RefusalReread> => {
    if (null !== refusalReread) {
      return refusalReread
    }

    const pending = (async (): Promise<RefusalReread> => {
      try {
        const sentAt = sessionStamp()
        const session = await repository.me()
        if ('anonymous' === session.tier) {
          // `/api/me` ne rejette pas sur un 401 : il rend l'anonyme. Le jeton ne vaut plus rien, la session est finie.
          // Déjà finie sous la génération du départ, elle est dite : on ne la réannonce pas (la relecture n'est pas
          // un geste de la personne). Partie sous une génération révolue, `markSessionExpired` ne change rien.
          if (state.sessionEnded && sentAt === generation) {
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
    })()
    refusalReread = pending
    // Libérée hors de l'IIFE : un `me()` qui lèverait de façon synchrone terminerait l'IIFE avant que `pending` soit
    // posée, et un `finally` interne laisserait alors la promesse en vol pour toujours. Enregistrée avant les `await`
    // des appelants, elle libère la place avant qu'aucun d'eux ne reprenne.
    void pending.finally(() => {
      if (refusalReread === pending) {
        refusalReread = null
      }
    })

    return pending
  }

  return {
    user: computed(() => state.user),
    tier: computed(() => state.tier),
    isAuthenticated: computed(() => 'anonymous' !== state.tier),
    isChecking: computed(() => state.isChecking),
    hasSessionEnded: computed(() => state.sessionEnded),
    sessionEndedCount: computed(() => state.sessionEndedCount),
    isTrusted: computed(() => 'trusted' === state.tier),
    isSuperAdmin: computed(() => hasRole(state.user, ROLE_SUPER)),
    login,
    logout,
    recheckEndedSession,
    rereadAfterRefusal,
    onSessionRestored,
    onAccountSwitched,
    checkAuth,
  }
}
