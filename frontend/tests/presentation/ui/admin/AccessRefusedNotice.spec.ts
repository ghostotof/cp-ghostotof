import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { defineComponent, h } from 'vue'
import AccessRefusedNotice from '../../../../src/presentation/ui/admin/AccessRefusedNotice.vue'
import { createAppI18n } from '../../../../src/presentation/i18n'
import { AUTH_REPOSITORY, markSessionExpired } from '../../../../src/application/auth/useAuth'
import {
  clearBackofficeAccessRefusal,
  markBackofficeAccessRefused,
  refusalScope,
} from '../../../../src/application/admin/shared/backofficeAccessRefusal'
import type { AuthRepository } from '../../../../src/domain/auth/repositories/AuthRepository'
import { sessionFor } from '../../../support/authSession'
import { signInAgain } from '../../../support/signInAgain'
import { expectNoAccessibilityViolation } from '../../../support/axe'

const StubPage = defineComponent({ setup: () => () => h('div') })

const repository: AuthRepository = {
  login: vi.fn(async () => ({ username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] })),
  logout: vi.fn(async () => undefined),
  hasWriteToken: () => true,
  me: vi.fn(async () => sessionFor(null)),
}

async function mountNotice(): Promise<{ wrapper: VueWrapper; router: Router }> {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      // `requiresAuth`, comme les vraies routes : sans lui le lien de connexion n'a pas de `redirect` (#748, R16, F8).
      { path: '/:locale(fr|en)/admin/case-studies', name: 'admin-case-studies', component: StubPage, meta: { requiresAuth: true } },
      { path: '/:locale(fr|en)/admin/technologies', name: 'admin-technologies', component: StubPage, meta: { requiresAuth: true } },
      { path: '/:locale(fr|en)/login', name: 'login', component: StubPage },
    ],
  })
  await router.push('/fr/admin/case-studies')
  await router.isReady()

  const wrapper = mount(AccessRefusedNotice, {
    attachTo: document.body,
    global: { plugins: [createAppI18n(), router], provide: { [AUTH_REPOSITORY as symbol]: repository } },
  })

  return { wrapper, router }
}

/**
 * L'alerte « accès refusé » du backoffice (#748, R17, F4), rendue une fois par `AdminLayout` : un refus réel
 * (un 403 qui n'est pas une fin de session) la lève, la navigation et la fin de session l'effacent.
 */
/** Un refus réel d'une requête partie sous le périmètre courant (jamais d'une page quittée, voir R28). */
function refuse(): void {
  markBackofficeAccessRefused({ kind: 'read', scope: refusalScope() })
}

describe('AccessRefusedNotice (#748, R17)', () => {
  afterEach(async () => {
    clearBackofficeAccessRefusal()
    document.body.innerHTML = ''
    await signInAgain()
  })

  it("n'affiche rien tant qu'aucun refus n'a eu lieu", async () => {
    const { wrapper } = await mountNotice()

    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(wrapper.text()).toBe('')
    wrapper.unmount()
  })

  it("un refus insère une alerte neutre et le lien « Changer de compte », dans le même onglet", async () => {
    const { wrapper } = await mountNotice()

    refuse()
    await flushPromises()

    const alert = wrapper.get('[role="alert"]')
    expect(alert.text()).toContain('Le serveur refuse cette demande à votre session')
    const link = alert.get('a')
    expect(link.text()).toBe('Changer de compte')
    expect(link.attributes('href')).toBe('/fr/login?redirect=/fr/admin/case-studies&reauth=1')
    expect(link.attributes('target')).toBeUndefined()
    await expectNoAccessibilityViolation(wrapper)
    wrapper.unmount()
  })

  it("un second refus remplace le message : l'alerte et son lien restent le même élément", async () => {
    const { wrapper } = await mountNotice()
    refuse()
    await flushPromises()
    const alert = wrapper.get('[role="alert"]').element
    const link = wrapper.get('a').element

    refuse()
    await flushPromises()

    expect(wrapper.findAll('[role="alert"]')).toHaveLength(1)
    expect(wrapper.get('[role="alert"]').element).toBe(alert)
    expect(wrapper.get('a').element).toBe(link)
    wrapper.unmount()
  })

  it("la navigation efface l'alerte", async () => {
    const { wrapper, router } = await mountNotice()
    refuse()
    await flushPromises()

    await router.push('/fr/admin/technologies')
    await flushPromises()

    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it("un changement du seul fragment garde l'alerte et le périmètre (le lien d'évitement, R38)", async () => {
    const { wrapper, router } = await mountNotice()
    refuse()
    await flushPromises()
    const scope = refusalScope()

    await router.push({ hash: '#main-content' })
    await flushPromises()

    expect(router.currentRoute.value.fullPath).toBe('/fr/admin/case-studies#main-content')
    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    expect(refusalScope()).toBe(scope)
    wrapper.unmount()
  })

  it("un changement de requête efface l'alerte", async () => {
    const { wrapper, router } = await mountNotice()
    refuse()
    await flushPromises()

    await router.push({ query: { page: '2' } })
    await flushPromises()

    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it("une requête aux paramètres permutés garde l'alerte : le routeur n'y voit pas de navigation (R54, CR5-8)", async () => {
    const { wrapper, router } = await mountNotice()
    await router.push('/fr/admin/case-studies?a=1&b=2')
    await flushPromises()
    refuse()
    await flushPromises()
    const scope = refusalScope()

    // vue-router compare les paramètres clé par clé : cette « navigation » est un doublon, il ne l'exécute pas.
    await router.push({ query: { b: '2', a: '1' } })
    await flushPromises()

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    expect(refusalScope()).toBe(scope)
    wrapper.unmount()
  })

  it("une session finie masque l'alerte et l'efface : elle ne revient pas avec la session", async () => {
    const { wrapper } = await mountNotice()
    refuse()
    await flushPromises()

    markSessionExpired()
    await flushPromises()
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)

    await signInAgain()
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it("le démontage efface le refus : une autre visite du backoffice repart sans alerte", async () => {
    const first = await mountNotice()
    refuse()
    await flushPromises()
    first.wrapper.unmount()

    const second = await mountNotice()

    expect(second.wrapper.find('[role="alert"]').exists()).toBe(false)
    second.wrapper.unmount()
  })

  it("un refus d'écriture arrivé après le démontage ne s'affiche pas au montage suivant (R48, CR5-1)", async () => {
    const first = await mountNotice()
    first.wrapper.unmount()

    // La personne a quitté le backoffice ; son enregistrement, parti avant, est refusé maintenant : aucune alerte n'est
    // montée pour l'afficher, mais le compteur monte.
    markBackofficeAccessRefused({ kind: 'write' })

    const second = await mountNotice()
    await flushPromises()

    expect(second.wrapper.find('[data-testid="admin-access-refused"]').exists()).toBe(false)
    second.wrapper.unmount()
  })
})
