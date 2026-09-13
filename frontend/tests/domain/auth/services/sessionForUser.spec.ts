import { describe, expect, it } from 'vitest'
import { sessionForUser } from '../../../../src/domain/auth/services/sessionForUser'
import type { AuthenticatedUser } from '../../../../src/domain/auth/entities/AuthenticatedUser'

describe('sessionForUser', () => {
  it('un compte ROLE_TRUSTED est au palier de confiance', () => {
    const user: AuthenticatedUser = { username: 'jane', roles: ['ROLE_TRUSTED', 'ROLE_USER'] }

    expect(sessionForUser(user)).toEqual({ tier: 'trusted', user })
  })

  it('un compte ROLE_SUPER est au palier de confiance (la hiérarchie backend l\'englobe, sans expansion dans la réponse)', () => {
    const user: AuthenticatedUser = { username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] }

    expect(sessionForUser(user)).toEqual({ tier: 'trusted', user })
  })

  it('un compte réel qui n\'a que ROLE_USER reste au palier de base, identité conservée', () => {
    const user: AuthenticatedUser = { username: 'demo', roles: ['ROLE_USER'] }

    expect(sessionForUser(user)).toEqual({ tier: 'base', user })
  })
})
