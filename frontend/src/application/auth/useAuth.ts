import { computed, inject, reactive, readonly, watch, type ComputedRef, type InjectionKey } from 'vue'
import type { AuthRepository } from '../../domain/auth/repositories/AuthRepository'
import type { AuthenticatedUser } from '../../domain/auth/entities/AuthenticatedUser'
import { hasRole } from '../../domain/auth/services/hasRole'

export const ROLE_SUPER = 'ROLE_SUPER'

export const AUTH_REPOSITORY: InjectionKey<AuthRepository> = Symbol('AuthRepository')

/**
 * Contrairement à usePortfolioContent (purement dérivé, sans état propre),
 * l'utilisateur connecté doit être partagé entre des composants sans lien
 * parent/enfant direct (AppHeader, LoginPage) et survivre à une navigation :
 * c'est donc un état singleton au niveau du module, créé une seule fois,
 * plutôt que recréé à chaque appel de useAuth().
 */
const state = reactive<{ user: AuthenticatedUser | null; isChecking: boolean }>({
  user: null,
  isChecking: true,
})

/**
 * Lecture seule de l'état d'auth, exposée à part de useAuth() : un garde de
 * navigation (presentation/router/index.ts) s'exécute hors contexte de
 * composant, où inject() n'est pas utilisable.
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

export interface UseAuthResult {
  user: ComputedRef<AuthenticatedUser | null>
  isAuthenticated: ComputedRef<boolean>
  isChecking: ComputedRef<boolean>
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
    state.user = await repository.login(username, password)
  }

  const logout = async (): Promise<void> => {
    await repository.logout()
    state.user = null
  }

  const checkAuth = async (): Promise<void> => {
    state.isChecking = true
    try {
      state.user = await repository.me()
    } finally {
      state.isChecking = false
    }
  }

  return {
    user: computed(() => state.user),
    isAuthenticated: computed(() => null !== state.user),
    isChecking: computed(() => state.isChecking),
    isSuperAdmin: computed(() => hasRole(state.user, ROLE_SUPER)),
    login,
    logout,
    checkAuth,
  }
}
