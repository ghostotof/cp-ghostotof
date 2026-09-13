import type { AuthenticatedUser } from '../entities/AuthenticatedUser'
import type { AuthSession } from '../entities/AuthSession'
import { ROLE_SUPER, ROLE_TRUSTED } from '../entities/Role'
import { hasRole } from './hasRole'

/**
 * Palier d'un compte identifié, d'après les rôles que le backend renvoie.
 *
 * Le backend renvoie les rôles *stockés* (CpgUser::getRoles()), pas les rôles
 * *atteignables* : la `role_hierarchy` (ROLE_SUPER ⊃ ROLE_TRUSTED ⊃ ROLE_USER,
 * ADR 0003 D7) n'est appliquée que par le vérificateur d'autorisation
 * Symfony, jamais expansée dans une réponse. Un super-admin arrive donc avec
 * ['ROLE_SUPER', 'ROLE_USER'] et sans ROLE_TRUSTED explicite. C'est le seul
 * endroit du frontend qui reflète cette implication ; si la hiérarchie
 * backend change, c'est ici qu'on la suit.
 */
export function sessionForUser(user: AuthenticatedUser): AuthSession {
  const isTrusted = hasRole(user, ROLE_TRUSTED) || hasRole(user, ROLE_SUPER)

  return { tier: isTrusted ? 'trusted' : 'base', user }
}
