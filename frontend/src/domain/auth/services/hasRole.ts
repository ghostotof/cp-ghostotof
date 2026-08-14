import type { AuthenticatedUser } from '../entities/AuthenticatedUser'

/**
 * Fonction pure (pas de dépendance à Vue) : utilisable aussi bien dans un
 * composant via useAuth() que dans un garde de navigation (router/index.ts),
 * qui s'exécute hors contexte de composant où inject() n'est pas disponible.
 */
export function hasRole(user: AuthenticatedUser | null, role: string): boolean {
  return null !== user && user.roles.includes(role)
}
