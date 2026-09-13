import type { AuthenticatedUser } from '../../src/domain/auth/entities/AuthenticatedUser'
import { ANONYMOUS_SESSION, type AuthSession } from '../../src/domain/auth/entities/AuthSession'
import { sessionForUser } from '../../src/domain/auth/services/sessionForUser'

/**
 * Raccourci pour les stubs de AuthRepository.me() : un utilisateur (ou null)
 * → la session que le backend décrirait pour lui. Évite de répéter la
 * dérivation de palier dans chaque spec.
 */
export function sessionFor(user: AuthenticatedUser | null): AuthSession {
  return null === user ? ANONYMOUS_SESSION : sessionForUser(user)
}
