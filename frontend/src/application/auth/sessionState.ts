import { reactive, readonly, watch } from 'vue'
import type { AuthenticatedUser } from '../../domain/auth/entities/AuthenticatedUser'
import { ANONYMOUS_SESSION, BASE_ACCESS_SESSION, type AccessTier, type AuthSession } from '../../domain/auth/entities/AuthSession'
import { clearBaseAccessExpiry, resetBaseAccessExpiryForTests, scheduleBaseAccessExpiry } from './baseAccessExpiry'
import { advanceGeneration } from './sessionGeneration'

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
 *
 * **Non exporté** (#748, R42, St4-5) : ce module est le seul à l'écrire. Les autres modules d'`application/auth/` passent
 * par des fonctions nommées (`applySession`, `markFirstCheckDone`, `raiseSessionEnded`,
 * `lowerSessionEnded`) ; tout le reste lit `authState`.
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
 * Lecture seule de l'état d'auth, exposée à part de useAuth() : un garde de
 * navigation (presentation/router/index.ts) ou un `watch` de page s'exécute
 * hors contexte de composant, où inject() n'est pas utilisable.
 */
export const authState = readonly(state)

/**
 * La première vérification (celle de `main.ts`) a fini, réussie ou non : `isChecking` retombe. Il **naît vrai** et ne
 * remonte jamais : tant qu'il l'est, il cache le groupe de droite de l'en-tête et fait attendre le garde de route
 * (`waitForAuthCheck`), c'est son seul rôle. Aucune relecture de `/api/me` (le retour sur l'onglet, un refus réel) ne le
 * touche : le lever à chaque retour ferait disparaître puis réapparaître l'en-tête (#748, R8).
 */
export function markFirstCheckDone(): void {
  state.isChecking = false
}

/**
 * Le **dernier compte affiché** (#748, R26) : l'identifiant et les rôles de la dernière session appliquée **avec** un
 * compte. C'est la définition de « le même compte » : la page ouverte est celle de ce compte, tant qu'on ne l'a pas
 * quittée par un geste de la personne. Défini ici, et non dans `sessionLifecycle.ts` qui l'importe, parce que
 * `applySession` le pose.
 */
export interface LastAccount {
  readonly username: string
  readonly roles: readonly string[]
}

/**
 * Posé par `applySession` quand la session porte un compte ; **gardé** quand l'en-tête devient anonyme (fin de
 * session) ou passe au palier de base (relecture après un refus) — la page ouverte est toujours la sienne — ;
 * oublié par `forgetLastAccount` (`logout()`, accès de base obtenu : la personne a quitté ce compte).
 */
let lastAccount: LastAccount | null = null

/** Le dernier compte affiché, ou `null` s'il n'y en a pas (jamais, ou oublié). Voir `lastAccount`. */
export function getLastAccount(): LastAccount | null {
  return lastAccount
}

/** La personne a quitté son compte (déconnexion, accès de base obtenu) : plus aucun compte n'est retenu. */
export function forgetLastAccount(): void {
  lastAccount = null
}

/**
 * La session est finie : `sessionEnded` monte et le compteur de fins augmente (le bandeau se réannonce, même quand la
 * session l'était déjà). Seule `markSessionExpired` (`sessionLifecycle.ts`) l'appelle, qui juge la génération.
 */
export function raiseSessionEnded(): void {
  state.sessionEnded = true
  state.sessionEndedCount += 1
}

/**
 * `sessionEnded` retombe. Deux appelants seulement : `restoreSession` (`sessionLifecycle.ts`, un compte est revenu) et
 * `markBaseAccessGranted` (l'accès de base obtenu clôt la fin).
 */
export function lowerSessionEnded(): void {
  state.sessionEnded = false
}

/**
 * Pose le palier et le compte. **Ne touche jamais à `sessionEnded`** : la fin est l'affaire de
 * `markSessionExpired`, ses deux descentes celles de `restoreSession` (`sessionLifecycle.ts`) et de
 * `markBaseAccessGranted` ; seules les deux premières jugent la génération et le jeton d'écriture. **N'avance pas non
 * plus la génération** : une session *lue* est la réponse d'un envoi, pas une
 * décision de l'onglet (celles-ci l'avancent : voir `sessionGeneration.ts`).
 */
export function applySession(session: AuthSession): void {
  state.tier = session.tier
  state.user = session.user
  if (null !== session.user) {
    lastAccount = { username: session.user.username, roles: [...session.user.roles] }
  }
  // Tout changement de session rend la minuterie précédente caduque : un
  // login remplace le cookie D6 par celui du compte, un logout l'efface.
  clearBaseAccessExpiry()
}

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
 * ce qu'on sait déjà. Elle baisse aussi `sessionEnded`. Exposée hors composant (comme authState) parce que
 * l'appelant, useBaseAccess, ne doit pas dépendre de AUTH_REPOSITORY.
 */
export function markBaseAccessGranted(expiresAt: Date | null = null): void {
  // Une session décidée dans l'onglet : tout `/api/me` parti avant (anonyme, peut-être) est périmé (R26, CR3-4).
  advanceGeneration()
  applySession(BASE_ACCESS_SESSION)
  forgetLastAccount()
  // Une session décidée dans l'onglet clôt la fin, comme `login()` (R41, CR4-3) : l'accès de base est un jeton neuf,
  // il n'y a plus de session finie à annoncer. Sans signal : la page de l'accès instantané ne rejoue rien, et la
  // génération vient d'avancer.
  lowerSessionEnded()
  if (null !== expiresAt) {
    scheduleBaseAccessExpiry(expiresAt, markBaseAccessExpired)
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
    // Le palier change dans l'onglet : un `/api/me` parti avant, qui rendrait encore « base », est périmé (R26, CR3-4).
    advanceGeneration()
    applySession(ANONYMOUS_SESSION)
  }
}

/** Réservée aux tests (`resetAuthStateForTests`), jamais appelée par `src/` : l'état de chargement, minuterie comprise. */
export function resetSessionStateForTests(): void {
  state.tier = ANONYMOUS_SESSION.tier
  state.user = ANONYMOUS_SESSION.user
  state.isChecking = true
  state.sessionEnded = false
  state.sessionEndedCount = 0
  lastAccount = null
  resetBaseAccessExpiryForTests()
}
