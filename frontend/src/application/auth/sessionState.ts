import { reactive, readonly, watch } from 'vue'
import type { AuthenticatedUser } from '../../domain/auth/entities/AuthenticatedUser'
import { ANONYMOUS_SESSION, BASE_ACCESS_SESSION, type AccessTier, type AuthSession } from '../../domain/auth/entities/AuthSession'
import { clearBaseAccessExpiry, resetBaseAccessExpiryForTests, scheduleBaseAccessExpiry } from './baseAccessExpiry'

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
 * Exporté pour les seuls modules d'`application/auth/` (`sessionLifecycle`, `useAuth`) : ce sont les seuls qui
 * l'écrivent. Tout le reste lit `authState`.
 */
export const state = reactive<{ tier: AccessTier; user: AuthenticatedUser | null; isChecking: boolean; sessionEnded: boolean; sessionEndedCount: number }>({
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
 * Vrai dès que la première vérification (celle de `main.ts`) a fini, réussie ou non. Avant elle, `isChecking`
 * cache le groupe de droite de l'en-tête et fait attendre le garde de route (`waitForAuthCheck`) : c'est son
 * seul rôle. Après elle, une relecture de `/api/me` (le retour sur l'onglet, un refus réel) est **silencieuse** :
 * y toucher ferait disparaître puis réapparaître l'en-tête à chaque retour (#748, R8).
 */
let hasCheckedOnce = false

/** Vrai quand la première vérification a fini : voir `hasCheckedOnce`. */
export function isFirstCheckDone(): boolean {
  return hasCheckedOnce
}

/** La première vérification a fini (réussie ou non) : `isChecking` retombe et les suivantes sont silencieuses. */
export function markFirstCheckDone(): void {
  hasCheckedOnce = true
  state.isChecking = false
}

/**
 * Pose le palier et le compte. **Ne touche jamais à `sessionEnded`** : la fin est l'affaire de
 * `markSessionExpired`, le retour de `restoreSession` (`sessionLifecycle.ts`), qui seuls jugent la génération et le
 * jeton d'écriture.
 */
export function applySession(session: AuthSession): void {
  state.tier = session.tier
  state.user = session.user
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
 * ce qu'on sait déjà. Exposée hors composant (comme authState) parce que
 * l'appelant, useBaseAccess, ne doit pas dépendre de AUTH_REPOSITORY.
 */
export function markBaseAccessGranted(expiresAt: Date | null = null): void {
  applySession(BASE_ACCESS_SESSION)
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
  hasCheckedOnce = false
  resetBaseAccessExpiryForTests()
}
