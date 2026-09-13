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

function applySession(session: AuthSession): void {
  state.tier = session.tier
  state.user = session.user
  // Tout changement de session rend la minuterie précédente caduque : un
  // login remplace le cookie D6 par celui du compte, un logout l'efface.
  clearBaseAccessExpiry()
}

function clearBaseAccessExpiry(): void {
  if (null !== baseAccessExpiryTimer) {
    clearTimeout(baseAccessExpiryTimer)
    baseAccessExpiryTimer = null
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
  baseAccessExpiryTimer = setTimeout(() => {
    baseAccessExpiryTimer = null
    markBaseAccessExpired()
  }, delay)
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
      // Lue avant applySession(), qui efface la mémoire : si le serveur
      // confirme le palier de base et qu'une échéance future est connue, la
      // minuterie reprend là où le rechargement l'avait interrompue.
      const rememberedExpiry = recallBaseAccessExpiry()
      const session = await repository.me()
      applySession(session)
      if ('base' === session.tier && null === session.user && null !== rememberedExpiry && rememberedExpiry.getTime() > Date.now()) {
        scheduleBaseAccessExpiry(rememberedExpiry)
      }
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
