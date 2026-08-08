import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { AUTH_REPOSITORY, useAuth } from './useAuth'
import type { AuthRepository } from '../../domain/auth/repositories/AuthRepository'
import type { AuthenticatedUser } from '../../domain/auth/entities/AuthenticatedUser'

function createStubRepository(overrides: Partial<AuthRepository> = {}): AuthRepository {
  return {
    login: vi.fn(async () => ({ username: 'jane' }) satisfies AuthenticatedUser),
    logout: vi.fn(async () => undefined),
    me: vi.fn(async () => null),
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
    const repository = createStubRepository({ me: vi.fn(async () => ({ username: 'jane' })) })
    const auth = mountWithComposable(repository)

    expect(auth.isAuthenticated.value).toBe(false)

    await auth.checkAuth()

    expect(auth.isAuthenticated.value).toBe(true)
    expect(auth.user.value).toEqual({ username: 'jane' })
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
})
