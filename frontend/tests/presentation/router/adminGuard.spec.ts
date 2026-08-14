import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent } from 'vue'
import { router } from '../../../src/presentation/router'
import { AUTH_REPOSITORY, useAuth } from '../../../src/application/auth/useAuth'
import type { AuthRepository } from '../../../src/domain/auth/repositories/AuthRepository'
import type { AuthenticatedUser } from '../../../src/domain/auth/entities/AuthenticatedUser'

/**
 * Exerce le routeur singleton (presentation/router/index.ts) plutôt qu'une
 * instance locale : le garde `requiresAuth`/`roles` protégeant /admin est
 * enregistré directement sur ce routeur via router.beforeEach(), il n'existe
 * pas ailleurs pour pouvoir le rejouer sur un routeur reconstruit.
 */

function createStubRepository(user: AuthenticatedUser | null): AuthRepository {
  return {
    login: vi.fn(async () => user ?? { username: 'jane', roles: ['ROLE_USER'] }),
    logout: vi.fn(async () => undefined),
    me: vi.fn(async () => user),
  }
}

async function primeAuthState(user: AuthenticatedUser | null): Promise<void> {
  const repository = createStubRepository(user)
  const Probe = defineComponent({
    setup() {
      return { auth: useAuth() }
    },
    template: '<div />',
  })
  const wrapper = mount(Probe, { global: { provide: { [AUTH_REPOSITORY as symbol]: repository } } })
  await wrapper.vm.auth.checkAuth()
  wrapper.unmount()
}

describe('router — garde /admin (ROLE_SUPER)', () => {
  beforeEach(async () => {
    await router.push('/fr')
    await router.isReady()
  })

  it('anonyme : redirige vers /login avec le chemin d\'origine en paramètre redirect', async () => {
    await primeAuthState(null)

    await router.push('/fr/admin/technologies')

    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query.redirect).toBe('/fr/admin/technologies')
  })

  it('authentifié sans ROLE_SUPER : redirige vers /forbidden', async () => {
    await primeAuthState({ username: 'jane', roles: ['ROLE_USER'] })

    await router.push('/fr/admin/technologies')

    expect(router.currentRoute.value.name).toBe('forbidden')
  })

  it('authentifié ROLE_SUPER : accède à la page demandée', async () => {
    await primeAuthState({ username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] })

    await router.push('/fr/admin/technologies')

    expect(router.currentRoute.value.name).toBe('admin-technologies')
  })

  it('authentifié ROLE_SUPER : /admin redirige vers la première section', async () => {
    await primeAuthState({ username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] })

    await router.push('/fr/admin')

    expect(router.currentRoute.value.name).toBe('admin-technologies')
  })

  it('attend la résolution de checkAuth() avant de trancher (évite une redirection prématurée au rechargement de page)', async () => {
    let resolveMe: (user: AuthenticatedUser | null) => void = () => {}
    const repository: AuthRepository = {
      login: vi.fn(),
      logout: vi.fn(),
      me: vi.fn(() => new Promise<AuthenticatedUser | null>((resolve) => (resolveMe = resolve))),
    }
    const Probe = defineComponent({
      setup() {
        return { auth: useAuth() }
      },
      template: '<div />',
    })
    const wrapper = mount(Probe, { global: { provide: { [AUTH_REPOSITORY as symbol]: repository } } })

    const checkAuthPromise = wrapper.vm.auth.checkAuth()
    const pushPromise = router.push('/fr/admin/technologies')

    resolveMe({ username: 'super', roles: ['ROLE_SUPER'] })
    await checkAuthPromise
    await pushPromise

    expect(router.currentRoute.value.name).toBe('admin-technologies')
    wrapper.unmount()
  })
})
