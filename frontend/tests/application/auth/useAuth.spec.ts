import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { AUTH_REPOSITORY, authState, markBaseAccessGranted, useAuth } from '../../../src/application/auth/useAuth'
import type { AuthRepository } from '../../../src/domain/auth/repositories/AuthRepository'
import type { AuthenticatedUser } from '../../../src/domain/auth/entities/AuthenticatedUser'
import { BASE_ACCESS_SESSION } from '../../../src/domain/auth/entities/AuthSession'
import { sessionFor } from '../../support/authSession'

function createStubRepository(overrides: Partial<AuthRepository> = {}): AuthRepository {
  return {
    login: vi.fn(async () => ({ username: 'jane', roles: ['ROLE_USER'] }) satisfies AuthenticatedUser),
    logout: vi.fn(async () => undefined),
    me: vi.fn(async () => sessionFor(null)),
    ...overrides,
  }
}

/**
 * Un seul site d'appel à defineComponent (vue/one-component-per-file), réutilisé
 * par mountWithComposable() et par le test "repository manquant" ci-dessous.
 */
function createProbe() {
  let captured: ReturnType<typeof useAuth> | undefined

  const Probe = defineComponent({
    setup() {
      captured = useAuth()
      return () => h('div')
    },
  })

  return { Probe, getCaptured: () => captured }
}

function mountWithComposable(repository: AuthRepository) {
  const { Probe, getCaptured } = createProbe()

  mount(Probe, {
    global: {
      provide: { [AUTH_REPOSITORY as symbol]: repository },
    },
  })

  const captured = getCaptured()
  if (!captured) {
    throw new Error('useAuth() did not run during mount')
  }

  return captured
}

describe('useAuth', () => {
  beforeEach(async () => {
    // L'état est un singleton au niveau du module (partagé entre AppHeader et
    // LoginPage) : on le remet à "non connecté" avant chaque test pour les isoler.
    await mountWithComposable(createStubRepository()).checkAuth()
  })

  it("lève une erreur explicite si le repository n'a pas été fourni via provide", () => {
    const { Probe } = createProbe()
    expect(() => mount(Probe)).toThrow(/AuthRepository/)
  })

  it('checkAuth() hydrate isAuthenticated depuis repository.me()', async () => {
    const repository = createStubRepository({ me: vi.fn(async () => sessionFor({ username: 'jane', roles: ['ROLE_TRUSTED', 'ROLE_USER'] })) })
    const auth = mountWithComposable(repository)

    expect(auth.isAuthenticated.value).toBe(false)

    await auth.checkAuth()

    expect(auth.isAuthenticated.value).toBe(true)
    expect(auth.user.value).toEqual({ username: 'jane', roles: ['ROLE_TRUSTED', 'ROLE_USER'] })
  })

  it('isSuperAdmin reflète le rôle ROLE_SUPER du user courant', async () => {
    const repository = createStubRepository({
      me: vi.fn(async () => sessionFor({ username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] })),
    })
    const auth = mountWithComposable(repository)

    expect(auth.isSuperAdmin.value).toBe(false)

    await auth.checkAuth()

    expect(auth.isSuperAdmin.value).toBe(true)
  })

  it('login() authentifie et met à jour user/isAuthenticated', async () => {
    const repository = createStubRepository()
    const auth = mountWithComposable(repository)

    await auth.login('jane', 'password')

    expect(repository.login).toHaveBeenCalledWith('jane', 'password')
    expect(auth.isAuthenticated.value).toBe(true)
    expect(auth.user.value?.username).toBe('jane')
  })

  it('login() propage les erreurs du repository (ex. identifiants invalides) sans modifier l\'état', async () => {
    const repository = createStubRepository({ login: vi.fn(async () => Promise.reject(new Error('bad credentials'))) })
    const auth = mountWithComposable(repository)

    await expect(auth.login('jane', 'wrong')).rejects.toThrow('bad credentials')
    expect(auth.isAuthenticated.value).toBe(false)
  })

  it('logout() déconnecte et vide user', async () => {
    const repository = createStubRepository()
    const auth = mountWithComposable(repository)
    await auth.login('jane', 'password')

    await auth.logout()

    expect(repository.logout).toHaveBeenCalled()
    expect(auth.isAuthenticated.value).toBe(false)
    expect(auth.user.value).toBeNull()
  })

  describe('palier d\'accès (ADR 0003 D1 — trois cas)', () => {
    it('anonyme par défaut : tier=anonymous, ni authentifié ni de confiance', async () => {
      const auth = mountWithComposable(createStubRepository())

      await auth.checkAuth()

      expect(auth.tier.value).toBe('anonymous')
      expect(auth.isAuthenticated.value).toBe(false)
      expect(auth.isTrusted.value).toBe(false)
    })

    it('checkAuth() reconnaît le palier de base (403 sur /api/me) : authentifié, sans identité, pas de confiance', async () => {
      const auth = mountWithComposable(createStubRepository({ me: vi.fn(async () => BASE_ACCESS_SESSION) }))

      await auth.checkAuth()

      expect(auth.tier.value).toBe('base')
      expect(auth.isAuthenticated.value).toBe(true)
      expect(auth.isTrusted.value).toBe(false)
      expect(auth.user.value).toBeNull()
    })

    it('checkAuth() reconnaît le palier de confiance', async () => {
      const auth = mountWithComposable(createStubRepository({ me: vi.fn(async () => sessionFor({ username: 'jane', roles: ['ROLE_TRUSTED', 'ROLE_USER'] })) }))

      await auth.checkAuth()

      expect(auth.tier.value).toBe('trusted')
      expect(auth.isTrusted.value).toBe(true)
    })

    it('markBaseAccessGranted() fait passer un anonyme au palier de base, visible via authState (hors composant)', async () => {
      const auth = mountWithComposable(createStubRepository())
      await auth.checkAuth()

      markBaseAccessGranted()

      expect(auth.tier.value).toBe('base')
      expect(authState.tier).toBe('base')
      expect(authState.user).toBeNull()
    })

    it('login() depuis le palier de base avec un compte de confiance passe au palier de confiance', async () => {
      const auth = mountWithComposable(
        createStubRepository({ login: vi.fn(async () => ({ username: 'jane', roles: ['ROLE_TRUSTED', 'ROLE_USER'] })) }),
      )
      await auth.checkAuth()
      markBaseAccessGranted()

      await auth.login('jane', 'password')

      expect(auth.tier.value).toBe('trusted')
      expect(auth.user.value?.username).toBe('jane')
    })

    it('login() avec un compte réel sans ROLE_TRUSTED reste au palier de base, mais identifié', async () => {
      const auth = mountWithComposable(createStubRepository())

      await auth.login('jane', 'password')

      expect(auth.tier.value).toBe('base')
      expect(auth.user.value?.username).toBe('jane')
    })

    it('logout() depuis le palier de base ramène à anonyme', async () => {
      const auth = mountWithComposable(createStubRepository())
      await auth.checkAuth()
      markBaseAccessGranted()

      await auth.logout()

      expect(auth.tier.value).toBe('anonymous')
    })
  })
})
