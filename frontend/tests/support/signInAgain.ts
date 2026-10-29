import { vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { AUTH_REPOSITORY, authState, useAuth } from '../../src/application/auth/useAuth'
import { sessionForUser } from '../../src/domain/auth/services/sessionForUser'
import type { AuthRepository } from '../../src/domain/auth/repositories/AuthRepository'

/**
 * Un compte qui revient (#748, R4) : `hasSessionEnded` retombe, le seul signal de retour qui existe, celui qui
 * relance les lectures qu'une session finie avait interrompues. Le singleton d'authentification est partagé entre les
 * tests : un test qui a marqué la session finie l'appelle dans son `afterEach` pour repartir d'une session de compte
 * (après avoir démonté ses pages : une page laissée montée relirait sa lecture interrompue à ce retour).
 *
 * **En session finie, le retour passe par `recheckEndedSession()`**, comme au `focus` de la fenêtre (R18) : une
 * connexion (`login`) ne rappelle plus les abonnés — la page de connexion navigue elle-même —, elle ne rétablirait
 * donc rien en place. Le `me` rend `super`, le compte que ces tests connectent ; hors session finie il n'y a rien à
 * relire, et `login` pose le compte comme avant.
 */
export async function signInAgain(): Promise<void> {
  const account = { username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] }
  const repository: AuthRepository = {
    login: vi.fn(async () => account),
    logout: vi.fn(async () => undefined),
    hasWriteToken: () => true,
    me: vi.fn(async () => sessionForUser(account)),
  }
  let auth: ReturnType<typeof useAuth> | undefined
  const Probe = defineComponent({
    setup() {
      auth = useAuth()
      return () => h('div')
    },
  })
  const probe = mount(Probe, { global: { provide: { [AUTH_REPOSITORY as symbol]: repository } } })
  if (authState.sessionEnded) {
    await auth?.recheckEndedSession()
  } else {
    await auth?.login('super', 'x')
  }
  probe.unmount()
  await flushPromises()
}
