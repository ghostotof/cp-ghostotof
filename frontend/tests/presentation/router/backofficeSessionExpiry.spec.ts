import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent } from 'vue'
import { router } from '../../../src/presentation/router'
import { onBackofficeUnauthorized } from '../../../src/presentation/router/backofficeSessionExpiry'
import { AUTH_REPOSITORY, authState, useAuth } from '../../../src/application/auth/useAuth'
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
 * Un 401 du backoffice ferme la session côté interface, **sans déplacer la
 * personne** (#650 R1c, corrigé en S2) : la page reste montée, avec ce qu'elle
 * porte (une étude de cas à moitié rédigée). Le bandeau d'AdminLayout annonce la
 * fin de session et offre le lien de reconnexion.
 */
describe('backofficeSessionExpiry', () => {
  beforeEach(async () => {
    await signIn(SUPER)
    await router.push('/fr/admin/users')
    await router.isReady()
  })

  it('marque la session expirée (en-tête anonyme, fin de session annoncée) sans quitter la page', async () => {
    const replace = vi.spyOn(router, 'replace')
    const push = vi.spyOn(router, 'push')

    onBackofficeUnauthorized()
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect(authState.user).toBeNull()
    expect(authState.sessionEnded).toBe(true)
    expect(router.currentRoute.value.name).toBe('admin-users')
    expect(router.currentRoute.value.fullPath).toBe('/fr/admin/users')
    expect(replace).not.toHaveBeenCalled()
    expect(push).not.toHaveBeenCalled()
    replace.mockRestore()
    push.mockRestore()
  })

  it('plusieurs 401 simultanés (listes chargées en parallèle) laissent un seul état de fin de session', async () => {
    const handler = onBackofficeUnauthorized

    handler()
    handler()
    handler()

    expect(authState.sessionEnded).toBe(true)
    expect(router.currentRoute.value.fullPath).toBe('/fr/admin/users')
  })
})
