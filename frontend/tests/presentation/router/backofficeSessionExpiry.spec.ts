import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent } from 'vue'
import { router } from '../../../src/presentation/router'
import { createBackofficeSessionEndedHandler } from '../../../src/presentation/router/backofficeSessionExpiry'
import type { BackofficeSessionEndedHandler } from '../../../src/infrastructure/admin/shared/BackofficeHttpClient'
import {
  backofficeAccessRefused,
  clearBackofficeAccessRefusal,
} from '../../../src/application/admin/shared/backofficeAccessRefusal'
import { AUTH_REPOSITORY, authState, useAuth } from '../../../src/application/auth/useAuth'
import type { AuthRepository } from '../../../src/domain/auth/repositories/AuthRepository'
import type { AuthenticatedUser } from '../../../src/domain/auth/entities/AuthenticatedUser'
import { sessionFor } from '../../support/authSession'

const SUPER: AuthenticatedUser = { username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] }

/** Le nombre de lectures de `/api/me` : `me` est l'espion que les refus réels doivent dédoublonner. */
const me = vi.fn(async () => sessionFor(SUPER))

const repository: AuthRepository = {
  login: async () => SUPER,
  logout: async () => undefined,
  hasWriteToken: () => true,
  me,
}

async function signIn(user: AuthenticatedUser): Promise<void> {
  const signedIn: AuthRepository = { ...repository, login: async () => user, me: async () => sessionFor(user) }
  const Probe = defineComponent({
    setup: () => ({ auth: useAuth() }),
    template: '<div />',
  })
  const wrapper = mount(Probe, { global: { provide: { [AUTH_REPOSITORY as symbol]: signedIn } } })
  await wrapper.vm.auth.checkAuth()
  wrapper.unmount()
}

/** La réaction de production, construite comme `main.ts` le fait : sur le `useAuth()` d'un composant. */
function buildHandler(): { handler: BackofficeSessionEndedHandler; unmount: () => void } {
  const Probe = defineComponent({ setup: () => ({ handler: createBackofficeSessionEndedHandler(useAuth()) }), template: '<div />' })
  const wrapper = mount(Probe, { global: { provide: { [AUTH_REPOSITORY as symbol]: repository } } })

  return { handler: wrapper.vm.handler, unmount: () => wrapper.unmount() }
}

/**
 * Un 401 du backoffice ferme la session côté interface, **sans déplacer la
 * personne** (#650 R1c, corrigé en S2) : la page reste montée, avec ce qu'elle
 * porte (une étude de cas à moitié rédigée). Le bandeau d'AdminLayout annonce la
 * fin de session et offre le lien de reconnexion.
 */
describe('backofficeSessionExpiry', () => {
  let backofficeSessionEndedHandler: BackofficeSessionEndedHandler
  let unmount: () => void

  beforeEach(async () => {
    me.mockClear()
    await signIn(SUPER)
    ;({ handler: backofficeSessionEndedHandler, unmount } = buildHandler())
    await router.push('/fr/admin/users')
    await router.isReady()
  })

  afterEach(() => {
    unmount()
    clearBackofficeAccessRefusal()
  })

  it('marque la session expirée (en-tête anonyme, fin de session annoncée) sans quitter la page', async () => {
    const replace = vi.spyOn(router, 'replace')
    const push = vi.spyOn(router, 'push')

    backofficeSessionEndedHandler.ended(backofficeSessionEndedHandler.stampAtSend())
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

  it("plusieurs 401 partis sous la même génération (listes chargées en parallèle) n'annoncent qu'une fois", () => {
    const { stampAtSend, ended } = backofficeSessionEndedHandler
    const sent = stampAtSend()
    const before = authCount()

    expect(ended(sent)).toBe(true)
    expect(ended(sent)).toBe(true)
    expect(ended(sent)).toBe(true)

    expect(authState.sessionEnded).toBe(true)
    expect(authCount()).toBe(before + 1)
    expect(router.currentRoute.value.fullPath).toBe('/fr/admin/users')
  })

  it('un 401 parti avant le retour de la session ne la refinit pas : ended rend faux', async () => {
    const sent = backofficeSessionEndedHandler.stampAtSend()
    backofficeSessionEndedHandler.ended(sent)
    await signIn(SUPER)
    expect(authState.sessionEnded).toBe(false)

    expect(backofficeSessionEndedHandler.ended(sent)).toBe(false)
    expect(authState.sessionEnded).toBe(false)
  })
})

describe('backofficeSessionExpiry — refus réel (#748, R17)', () => {
  afterEach(() => {
    clearBackofficeAccessRefusal()
  })

  it("refused : l'alerte est levée et le palier relu une fois pour deux refus", async () => {
    me.mockClear()
    await signIn(SUPER)
    const { handler, unmount } = buildHandler()
    expect(backofficeAccessRefused.value).toBe(0)

    // Deux refus du même chargement (une lecture, une écriture) : deux alertes comptées, une seule relecture.
    handler.refused()
    handler.refused()
    await flushPromises()

    expect(backofficeAccessRefused.value).toBe(2)
    expect(me).toHaveBeenCalledTimes(1)
    unmount()
  })

  it('refused ne finit pas la session et ne quitte pas la page', async () => {
    await signIn(SUPER)
    await router.push('/fr/admin/users')
    const { handler, unmount } = buildHandler()

    handler.refused()
    await flushPromises()

    expect(authState.sessionEnded).toBe(false)
    expect(authState.user).not.toBeNull()
    expect(router.currentRoute.value.fullPath).toBe('/fr/admin/users')
    unmount()
  })
})

function authCount(): number {
  return authState.sessionEndedCount
}
