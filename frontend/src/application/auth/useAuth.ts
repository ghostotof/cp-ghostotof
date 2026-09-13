import { computed, inject, reactive, readonly, watch, type ComputedRef, type InjectionKey } from 'vue'
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
const state = reactive<{ tier: AccessTier; user: AuthenticatedUser | null; isChecking: boolean }>({
  tier: ANONYMOUS_SESSION.tier,
  user: ANONYMOUS_SESSION.user,
  isChecking: true,
})

function applySession(session: AuthSession): void {
  state.tier = session.tier
  state.user = session.user
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
export function markBaseAccessGranted(): void {
  applySession(BASE_ACCESS_SESSION)
}

export interface UseAuthResult {
  user: ComputedRef<AuthenticatedUser | null>
  /** Palier courant (ADR 0003 D1) — la source de vérité pour l'en-tête. */
  tier: ComputedRef<AccessTier>
  /** Vrai dès qu'un jeton valide existe, palier de base compris. */
  isAuthenticated: ComputedRef<boolean>
  isChecking: ComputedRef<boolean>
  /** ROLE_TRUSTED (ou ROLE_SUPER) : le seul palier qui ouvre le CV et /api/me. */
  isTrusted: ComputedRef<boolean>
  isSuperAdmin: ComputedRef<boolean>
  login: (username: string, password: string) => Promise<void>
  logout: () => Promise<void>
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
    applySession(sessionForUser(await repository.login(username, password)))
  }

  const logout = async (): Promise<void> => {
    await repository.logout()
    applySession(ANONYMOUS_SESSION)
  }

  const checkAuth = async (): Promise<void> => {
    state.isChecking = true
    try {
      applySession(await repository.me())
    } finally {
      state.isChecking = false
    }
  }

  return {
    user: computed(() => state.user),
    tier: computed(() => state.tier),
    isAuthenticated: computed(() => 'anonymous' !== state.tier),
    isChecking: computed(() => state.isChecking),
    isTrusted: computed(() => 'trusted' === state.tier),
    isSuperAdmin: computed(() => hasRole(state.user, ROLE_SUPER)),
    login,
    logout,
    checkAuth,
  }
}
