import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent } from 'vue'
import { router } from '../../../src/presentation/router'
import { createBackofficeSessionReaction } from '../../../src/presentation/router/backofficeSessionExpiry'
import type { BackofficeSessionReaction } from '../../../src/domain/admin/shared/BackofficeSessionReaction'
import {
  backofficeAccessRefused,
  clearBackofficeAccessRefusal,
  earlierSaveRefused,
  settleBackofficeAccessRefusalsOnMount,
} from '../../../src/application/admin/shared/backofficeAccessRefusal'
import { AUTH_REPOSITORY, authState, markBaseAccessGranted, markSessionExpired, useAuth } from '../../../src/application/auth/useAuth'
import { isCurrentGeneration } from '../../../src/application/auth/sessionGeneration'
import type { AuthRepository } from '../../../src/domain/auth/repositories/AuthRepository'
import type { AuthenticatedUser } from '../../../src/domain/auth/entities/AuthenticatedUser'
import { sessionFor } from '../../support/authSession'
import { signInAgain } from '../../support/signInAgain'

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
function buildReaction(): { handler: BackofficeSessionReaction; unmount: () => void } {
  const Probe = defineComponent({ setup: () => ({ handler: createBackofficeSessionReaction(useAuth()) }), template: '<div />' })
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
  let reaction: BackofficeSessionReaction
  let unmount: () => void

  beforeEach(async () => {
    me.mockClear()
    await signIn(SUPER)
    ;({ handler: reaction, unmount } = buildReaction())
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

    reaction.ended(reaction.stampAtSend('read'))
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
    const { stampAtSend, ended } = reaction
    const sent = stampAtSend('read')
    const before = authCount()

    expect(ended(sent)).toBe(true)
    expect(ended(sent)).toBe(true)
    expect(ended(sent)).toBe(true)

    expect(authState.sessionEnded).toBe(true)
    expect(authCount()).toBe(before + 1)
    expect(router.currentRoute.value.fullPath).toBe('/fr/admin/users')
  })

  it('un 401 parti avant le retour de la session ne la refinit pas : ended rend faux', async () => {
    const sent = reaction.stampAtSend('read')
    reaction.ended(sent)
    await signIn(SUPER)
    expect(authState.sessionEnded).toBe(false)

    expect(reaction.ended(sent)).toBe(false)
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
    const { handler, unmount } = buildReaction()
    expect(backofficeAccessRefused.value).toBe(0)

    // Deux refus du même chargement (une lecture, une écriture) : deux alertes comptées, une seule relecture.
    handler.refused(handler.stampAtSend('read'))
    handler.refused(handler.stampAtSend('write'))
    await flushPromises()

    expect(backofficeAccessRefused.value).toBe(2)
    expect(me).toHaveBeenCalledTimes(1)
    unmount()
  })

  it("un refus parti avant une navigation n'est ni compté ni relu", async () => {
    me.mockClear()
    await signIn(SUPER)
    const { handler, unmount } = buildReaction()

    // La page X envoie sa lecture, la personne navigue (la navigation efface les refus), puis le 403 de X arrive.
    const sentFromPreviousPage = handler.stampAtSend('read')
    clearBackofficeAccessRefusal()
    handler.refused(sentFromPreviousPage)
    await flushPromises()

    expect(backofficeAccessRefused.value).toBe(0)
    expect(me).not.toHaveBeenCalled()
    unmount()
  })

  it("un refus d'écriture parti avant une navigation est compté et relu : la personne a fait le geste (R38)", async () => {
    me.mockClear()
    await signIn(SUPER)
    const { handler, unmount } = buildReaction()

    // « Enregistrer » part, la personne navigue (la navigation efface les refus), puis le 403 de l'écriture arrive.
    const sentFromPreviousPage = handler.stampAtSend('write')
    clearBackofficeAccessRefusal()
    handler.refused(sentFromPreviousPage)
    await flushPromises()

    expect(backofficeAccessRefused.value).toBe(1)
    expect(me).toHaveBeenCalledTimes(1)
    unmount()
  })

  it('un refus du périmètre courant compte et relit, même après une navigation', async () => {
    me.mockClear()
    await signIn(SUPER)
    const { handler, unmount } = buildReaction()

    clearBackofficeAccessRefusal()
    // La page Y envoie sa requête sous le périmètre courant : son refus est un vrai refus.
    handler.refused(handler.stampAtSend('read'))
    await flushPromises()

    expect(backofficeAccessRefused.value).toBe(1)
    expect(me).toHaveBeenCalledTimes(1)
    unmount()
  })

  it.each(['read', 'write'] as const)(
    "le même compte, génération avancée depuis l'envoi (fin puis retour) : le refus (%s) est compté et relu (R63, décision 2)",
    async (kind) => {
      me.mockClear()
      await signIn(SUPER)
      const { handler, unmount } = buildReaction()

      // La requête part sous le compte « super » ; la session finit, puis le même compte revient (un autre onglet, une
      // reconnexion) : la génération a avancé deux fois, le compte n'a pas changé. Aucune alerte n'est montée ici, le
      // périmètre ne bouge donc pas à la fin : seul le compte juge, ce que le test veut isoler.
      const sent = handler.stampAtSend(kind)
      markSessionExpired()
      await signInAgain()
      expect(isCurrentGeneration(sent.session)).toBe(false)
      me.mockClear()
      handler.refused(sent)
      await flushPromises()

      expect(backofficeAccessRefused.value).toBe(1)
      expect(me).toHaveBeenCalledTimes(1)
      unmount()
    },
  )

  it.each(['read', 'write'] as const)(
    "un autre compte depuis l'envoi : le refus (%s) n'est ni compté ni relu (R48, R63)",
    async (kind) => {
      me.mockClear()
      await signIn(SUPER)
      const { handler, unmount } = buildReaction()

      // Le témoin : la requête part sous « super », puis un autre compte est lu hors session finie. Son 403 tardif est
      // celui d'une session qui n'est plus la courante, le palier relu serait celui d'un compte qu'il ne concerne pas.
      const sent = handler.stampAtSend(kind)
      await signIn({ username: 'autre', roles: ['ROLE_SUPER', 'ROLE_USER'] })
      me.mockClear()
      handler.refused(sent)
      await flushPromises()

      expect(backofficeAccessRefused.value).toBe(0)
      expect(me).not.toHaveBeenCalled()
      unmount()
    },
  )

  it("aucun compte retenu depuis l'envoi (accès de base obtenu) : le refus d'une écriture n'est ni compté ni relu", async () => {
    me.mockClear()
    await signIn(SUPER)
    const { handler, unmount } = buildReaction()

    // « Accès instantané » oublie le dernier compte : `null` diffère de tout identifiant.
    const sent = handler.stampAtSend('write')
    markBaseAccessGranted()
    handler.refused(sent)
    await flushPromises()

    expect(backofficeAccessRefused.value).toBe(0)
    expect(me).not.toHaveBeenCalled()
    unmount()
  })

  // R77 (CR7-2, CR7-3), tests de caractérisation : l'identité du compte est son **nom**, et un refus n'est écarté que
  // si le compte de l'envoi et celui de la réception diffèrent — `null` d'un seul côté compris.
  it.each(['read', 'write'] as const)(
    "aucun compte à l'envoi ni à la réception : le refus (%s) est compté et relu, `null` des deux côtés est le même « aucun » (R77)",
    async (kind) => {
      me.mockClear()
      await signIn(SUPER)
      const { handler, unmount } = buildReaction()

      // L'accès de base oublie le dernier compte : l'envoi et la réception relèvent tous deux `null`.
      markBaseAccessGranted()
      const sent = handler.stampAtSend(kind)
      expect(sent.account).toBeNull()
      handler.refused(sent)
      await flushPromises()

      expect(backofficeAccessRefused.value).toBe(1)
      expect(me).toHaveBeenCalledTimes(1)
      unmount()
    },
  )

  it("aucun compte à l'envoi, un compte à la réception : le refus est écarté (limite écrite, sans chemin)", async () => {
    me.mockClear()
    await signIn(SUPER)
    const { handler, unmount } = buildReaction()

    // Le cas que le garde du backoffice interdit (il attend la première vérification) : la requête part avant tout compte.
    markBaseAccessGranted()
    const sent = handler.stampAtSend('write')
    expect(sent.account).toBeNull()
    await signIn(SUPER)
    me.mockClear()
    handler.refused(sent)
    await flushPromises()

    expect(backofficeAccessRefused.value).toBe(0)
    expect(me).not.toHaveBeenCalled()
    unmount()
  })

  it.each(['read', 'write'] as const)(
    "déconnexion puis reconnexion au même nom entre l'envoi et le refus : le refus (%s) est compté, le nom est l'identité (R77)",
    async (kind) => {
      me.mockClear()
      await signIn(SUPER)
      const { handler, unmount } = buildReaction()

      // La requête part sous « super » ; la personne se déconnecte (le dernier compte est oublié), puis se reconnecte
      // sous le même nom : le compte à la réception est celui de l'envoi, par son nom.
      const sent = handler.stampAtSend(kind)
      const Probe = defineComponent({ setup: () => ({ auth: useAuth() }), template: '<div />' })
      const wrapper = mount(Probe, { global: { provide: { [AUTH_REPOSITORY as symbol]: repository } } })
      await wrapper.vm.auth.logout()
      wrapper.unmount()
      await signIn(SUPER)
      me.mockClear()
      handler.refused(sent)
      await flushPromises()

      expect(backofficeAccessRefused.value).toBe(1)
      expect(me).toHaveBeenCalledTimes(1)
      unmount()
    },
  )

  it("refused transmet le compte du timbre au comptage : l'écriture refusée n'est dite qu'à ce compte (R76)", async () => {
    await signIn(SUPER)
    const { handler, unmount } = buildReaction()

    // L'écriture part sous « super » : le comptage retient ce nom, que le montage de l'alerte compare au compte courant.
    handler.refused(handler.stampAtSend('write'))
    settleBackofficeAccessRefusalsOnMount('super')
    expect(earlierSaveRefused.value).toBe(true)
    expect(backofficeAccessRefused.value).toBe(1)

    clearBackofficeAccessRefusal()
    handler.refused(handler.stampAtSend('write'))
    settleBackofficeAccessRefusalsOnMount('autre')
    expect(earlierSaveRefused.value).toBe(false)
    expect(backofficeAccessRefused.value).toBe(0)
    unmount()
  })

  it("seule une lecture estampille le chargement : une écriture ne clôt pas la page d'arrivée, une lecture oui (#748, R122)", async () => {
    await signIn(SUPER)
    const { handler, unmount } = buildReaction()
    handler.refused(handler.stampAtSend('write'))
    const landing = handler.stampAtSend('read')
    settleBackofficeAccessRefusalsOnMount('super')

    // Une écriture partie après le montage ne relance aucune lecture : la page d'arrivée garde l'avis.
    handler.stampAtSend('write')
    handler.refused(landing)
    expect(earlierSaveRefused.value).toBe(true)

    // Une lecture partie après le montage la clôt : le refus tardif de la page d'arrivée rend le texte courant.
    handler.stampAtSend('read')
    handler.refused(landing)
    expect(earlierSaveRefused.value).toBe(false)
    unmount()
  })

  it('refused ne finit pas la session et ne quitte pas la page', async () => {
    await signIn(SUPER)
    await router.push('/fr/admin/users')
    const { handler, unmount } = buildReaction()

    handler.refused(handler.stampAtSend('read'))
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
