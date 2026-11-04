import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { useSessionRecheckOnFocus } from '../../../src/presentation/layout/useSessionRecheckOnFocus'
import { AUTH_REPOSITORY, markSessionExpired } from '../../../src/application/auth/useAuth'
import type { AuthRepository } from '../../../src/domain/auth/repositories/AuthRepository'
import { sessionFor } from '../../support/authSession'

function createRepository(): AuthRepository {
  return {
    login: vi.fn(async () => ({ username: 'jane', roles: ['ROLE_USER'] })),
    logout: vi.fn(async () => undefined),
    hasWriteToken: () => true,
    me: vi.fn(async () => sessionFor({ username: 'jane', roles: ['ROLE_TRUSTED', 'ROLE_USER'] })),
  }
}

function mountProbe(repository: AuthRepository) {
  const Probe = defineComponent({
    setup() {
      useSessionRecheckOnFocus()
      return () => h('div')
    },
  })

  return mount(Probe, { global: { provide: { [AUTH_REPOSITORY as symbol]: repository } } })
}

describe('useSessionRecheckOnFocus (#748, R8)', () => {
  it("au retour sur l'onglet (focus de la fenêtre), relit la session finie", async () => {
    const repository = createRepository()
    const wrapper = mountProbe(repository)
    markSessionExpired()

    window.dispatchEvent(new Event('focus'))
    await flushPromises()

    expect(repository.me).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it("ne relit rien tant que la session n'a pas pris fin", async () => {
    const repository = createRepository()
    const wrapper = mountProbe(repository)

    window.dispatchEvent(new Event('focus'))
    await flushPromises()

    expect(repository.me).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it("retire son écouteur au démontage : plus rien ne se relit ensuite", async () => {
    const repository = createRepository()
    const wrapper = mountProbe(repository)
    wrapper.unmount()
    markSessionExpired()

    window.dispatchEvent(new Event('focus'))
    await flushPromises()

    expect(repository.me).not.toHaveBeenCalled()
  })
})
