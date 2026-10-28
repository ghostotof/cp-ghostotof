import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent } from 'vue'
import { router } from '../../../src/presentation/router'
import { createBackofficeSessionExpiryHandler } from '../../../src/presentation/router/backofficeSessionExpiry'
import { AUTH_REPOSITORY, authState, markSessionExpired, useAuth } from '../../../src/application/auth/useAuth'
import type { AuthRepository } from '../../../src/domain/auth/repositories/AuthRepository'
import type { AuthenticatedUser } from '../../../src/domain/auth/entities/AuthenticatedUser'
import { sessionFor } from '../../support/authSession'

const SUPER: AuthenticatedUser = { username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] }

async function signIn(user: AuthenticatedUser): Promise<void> {
  const repository: AuthRepository = {
    login: async () => user,
    logout: async () => undefined,
    me: async () => sessionFor(user),
  }
  const Probe = defineComponent({
    setup: () => ({ auth: useAuth() }),
    template: '<div />',
  })
  const wrapper = mount(Probe, { global: { provide: { [AUTH_REPOSITORY as symbol]: repository } } })
  await wrapper.vm.auth.checkAuth()
  wrapper.unmount()
}

/**
 * Un 401 du backoffice ferme la session côté interface et mène à la connexion,
 * avec un retour à la page où se trouvait la personne (#650 R1c).
 */
describe('backofficeSessionExpiry', () => {
  beforeEach(async () => {
    await signIn(SUPER)
    await router.push('/fr/admin/users')
    await router.isReady()
  })

  it('marque la session expirée et mène à la connexion avec redirect vers la page courante', async () => {
    expect(router.currentRoute.value.name).toBe('admin-users')

    createBackofficeSessionExpiryHandler(router)()
    // La navigation est asynchrone (composant de page chargé à la demande).
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('login'))

    expect(authState.user).toBeNull()
    expect(router.currentRoute.value.params.locale).toBe('fr')
    expect(router.currentRoute.value.query.redirect).toBe('/fr/admin/users')
    // La page de connexion dit pourquoi elle s'ouvre (#650, R5a).
    expect(router.currentRoute.value.query.expired).toBe('1')
  })

  it('garde la langue de la page courante', async () => {
    await router.push('/en/admin/users')

    createBackofficeSessionExpiryHandler(router)()
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('login'))

    expect(router.currentRoute.value.params.locale).toBe('en')
    expect(router.currentRoute.value.query.redirect).toBe('/en/admin/users')
  })

  it('plusieurs 401 simultanés (listes chargées en parallèle) ne font qu\'une navigation', async () => {
    const handler = createBackofficeSessionExpiryHandler(router)
    const replace = vi.spyOn(router, 'replace')

    handler()
    handler()
    handler()
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('login'))

    expect(router.currentRoute.value.query.redirect).toBe('/fr/admin/users')
    expect(replace).toHaveBeenCalledTimes(1)
    replace.mockRestore()
  })

  it('déjà sur la page de connexion : ne renavigue pas (redirect conservé)', async () => {
    // La page de connexion n'ouvre pas à une personne encore connectée : la session est déjà finie.
    markSessionExpired()
    await router.push({ name: 'login', params: { locale: 'fr' }, query: { redirect: '/fr/admin/users' } })

    createBackofficeSessionExpiryHandler(router)()
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query.redirect).toBe('/fr/admin/users')
  })
})
