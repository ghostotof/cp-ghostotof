import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent } from 'vue'
import { router } from '../../../src/presentation/router'
import { AUTH_REPOSITORY, useAuth } from '../../../src/application/auth/useAuth'
import type { AuthRepository } from '../../../src/domain/auth/repositories/AuthRepository'
import type { AuthenticatedUser } from '../../../src/domain/auth/entities/AuthenticatedUser'
import type { AuthSession } from '../../../src/domain/auth/entities/AuthSession'
import { sessionFor } from '../../support/authSession'

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
    me: vi.fn(async () => sessionFor(user)),
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

  it('route publique set-password : accessible sans authentification, le jeton dans le fragment (audit A7)', async () => {
    await primeAuthState(null)

    await router.push('/en/set-password#deadbeef')

    expect(router.currentRoute.value.name).toBe('set-password')
    expect(router.currentRoute.value.params.token).toBeUndefined()
    expect(router.currentRoute.value.hash).toBe('#deadbeef')
    expect(router.currentRoute.value.meta.noindex).toBe(true)
    expect(router.currentRoute.value.meta.requiresAuth).toBeUndefined()
  })

  it("un ancien lien à segment (`set-password/<jeton>`) n'est plus une route : 404 (T4.4, repli retiré)", async () => {
    await primeAuthState(null)

    await router.push('/fr/set-password/deadbeefcafe')

    // Le segment est retiré du routeur : un secret ne peut plus arriver par le
    // chemin, donc plus jamais dans un access log ni dans canonical/hreflang.
    expect(router.currentRoute.value.name).toBe('not-found')
  })

  it('route publique set-password : le canonical et les hreflang du vrai routeur ne portent jamais le jeton du fragment', async () => {
    await primeAuthState(null)

    await router.push('/fr/set-password#deadbeefcafe')

    const hrefs = Array.from(document.head.querySelectorAll('link')).map((link) => link.getAttribute('href') ?? '')
    expect(hrefs.length).toBeGreaterThan(0)
    for (const href of hrefs) {
      expect(href).not.toContain('deadbeefcafe')
    }
    expect(document.querySelector('link[rel="canonical"]')?.getAttribute('href')).toBe(`${window.location.origin}/fr/set-password`)
  })

  it('attend la résolution de checkAuth() avant de trancher (évite une redirection prématurée au rechargement de page)', async () => {
    let resolveMe: (session: AuthSession) => void = () => {}
    const repository: AuthRepository = {
      login: vi.fn(),
      logout: vi.fn(),
      me: vi.fn(() => new Promise<AuthSession>((resolve) => (resolveMe = resolve))),
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

    resolveMe(sessionFor({ username: 'super', roles: ['ROLE_SUPER'] }))
    await checkAuthPromise
    await pushPromise

    expect(router.currentRoute.value.name).toBe('admin-technologies')
    wrapper.unmount()
  })
})
