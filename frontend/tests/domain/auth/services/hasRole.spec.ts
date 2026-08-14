import { describe, expect, it } from 'vitest'
import { hasRole } from '../../../../src/domain/auth/services/hasRole'
import type { AuthenticatedUser } from '../../../../src/domain/auth/entities/AuthenticatedUser'

describe('hasRole', () => {
  it('retourne false si aucun utilisateur n\'est connecté', () => {
    expect(hasRole(null, 'ROLE_SUPER')).toBe(false)
  })

  it('retourne false si l\'utilisateur n\'a pas le rôle demandé', () => {
    const user: AuthenticatedUser = { username: 'jane', roles: ['ROLE_USER'] }
    expect(hasRole(user, 'ROLE_SUPER')).toBe(false)
  })

  it('retourne true si l\'utilisateur a le rôle demandé', () => {
    const user: AuthenticatedUser = { username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] }
    expect(hasRole(user, 'ROLE_SUPER')).toBe(true)
  })
})
