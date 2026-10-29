import { vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { AUTH_REPOSITORY, useAuth } from '../../src/application/auth/useAuth'
import type { AuthRepository } from '../../src/domain/auth/repositories/AuthRepository'

/**
 * Un compte qui revient (#748, R4) : le `login` d'un composant sonde applique une session de compte, ce qui
 * fait retomber `hasSessionEnded` — le seul signal de retour qui existe, celui qui relance les lectures
 * qu'une session finie avait interrompues. Le singleton d'authentification est partagé entre les tests : un
 * test qui a marqué la session finie l'appelle dans son `afterEach` pour repartir d'une session de compte
 * (après avoir démonté ses pages : une page laissée montée relirait sa lecture interrompue à ce retour).
 */
export async function signInAgain(): Promise<void> {
  const repository: AuthRepository = {
    login: vi.fn(async () => ({ username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] })),
    logout: vi.fn(async () => undefined),
    me: vi.fn(async () => {
      throw new Error('inutilisé')
    }),
  }
  let auth: ReturnType<typeof useAuth> | undefined
  const Probe = defineComponent({
    setup() {
      auth = useAuth()
      return () => h('div')
    },
  })
  const probe = mount(Probe, { global: { provide: { [AUTH_REPOSITORY as symbol]: repository } } })
  await auth?.login('super', 'x')
  probe.unmount()
  await flushPromises()
}
