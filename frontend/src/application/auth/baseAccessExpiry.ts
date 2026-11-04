/**
 * Échéance du jeton D6 (accès de base) : la minuterie et sa mémoire locale. #748 (R25) l'a sortie de `useAuth.ts`.
 *
 * Ce module **n'importe rien d'`application/auth/`** : le rappel d'échéance est passé en paramètre par
 * `sessionState.ts`, qui sait ce qu'« expirer » veut dire. C'est ce qui évite un cycle d'import entre les deux.
 */

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

/** Arrête la minuterie et oublie l'échéance mémorisée. */
export function clearBaseAccessExpiry(): void {
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

/**
 * Arme l'échéance de l'accès de base : à `expiresAt`, `onExpiry` est appelé — l'en-tête repasse à anonyme, **un effet
 * d'affichage, sans aucun appel réseau** (#748, R23). Elle lit l'horloge du poste : un poste en avance ou en
 * retard, ou un onglet endormi dont la minuterie tarde, décale l'affichage, jamais l'accès (le serveur reste le seul
 * juge). Le JWT meurt à l'échéance ; le cookie, de session, reste jusqu'à la fermeture du navigateur ou au premier 401
 * d'une route authentifiée, qui l'efface.
 */
export function scheduleBaseAccessExpiry(expiresAt: Date, onExpiry: () => void): void {
  clearBaseAccessExpiry()
  try {
    localStorage.setItem(BASE_ACCESS_EXPIRY_STORAGE_KEY, expiresAt.toISOString())
  } catch {
    // Idem : le badge survivra moins bien à un rechargement, rien de plus.
  }
  const delay = Math.min(Math.max(expiresAt.getTime() - Date.now(), 0), MAX_TIMEOUT_MS)
  baseAccessExpiryTimer = setTimeout(() => {
    baseAccessExpiryTimer = null
    onExpiry()
  }, delay)
}

/** L'échéance mémorisée par un chargement précédent, ou `null` (absente, illisible, stockage indisponible). */
export function recallBaseAccessExpiry(): Date | null {
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

/** Réservée aux tests (`resetAuthStateForTests`), jamais appelée par `src/` : arrête la minuterie et vide la mémoire. */
export function resetBaseAccessExpiryForTests(): void {
  clearBaseAccessExpiry()
}
