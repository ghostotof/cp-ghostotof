import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { defineComponent, h } from 'vue'
import SessionEndedBanner from '../../../../src/presentation/ui/admin/SessionEndedBanner.vue'
import { createAppI18n } from '../../../../src/presentation/i18n'
import { AUTH_REPOSITORY, markSessionExpired, useAuth } from '../../../../src/application/auth/useAuth'
import type { AuthRepository } from '../../../../src/domain/auth/repositories/AuthRepository'
import type { AuthSession } from '../../../../src/domain/auth/entities/AuthSession'
import { sessionFor } from '../../../support/authSession'
import { expectNoAccessibilityViolation } from '../../../support/axe'

const StubPage = defineComponent({ setup: () => () => h('div') })

function createRepository(me: () => Promise<AuthSession>): AuthRepository {
  return {
    login: vi.fn(async () => ({ username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] })),
    logout: vi.fn(async () => undefined),
    me: vi.fn(me),
  }
}

/** Le singleton d'authentification est partagé : chaque test repart d'une session de compte. */
async function resetToSignedIn(repository: AuthRepository): Promise<void> {
  const Probe = defineComponent({ setup: () => ({ auth: useAuth() }), template: '<div />' })
  const probe = mount(Probe, { global: { provide: { [AUTH_REPOSITORY as symbol]: repository } } })
  await probe.vm.auth.login('super', 'x')
  probe.unmount()
}

async function mountBanner(path: string, repository: AuthRepository, locale: 'fr' | 'en' = 'fr'): Promise<VueWrapper> {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/:locale(fr|en)/admin/case-studies', name: 'admin-case-studies', component: StubPage },
      { path: '/:locale(fr|en)/login', name: 'login', component: StubPage },
    ],
  })
  await router.push(path)
  await router.isReady()

  // La langue de l'interface suit celle de la route (le routeur de production la pose).
  const i18n = createAppI18n()
  i18n.global.locale.value = locale

  return mount(SessionEndedBanner, {
    attachTo: document.body,
    global: { plugins: [i18n, router], provide: { [AUTH_REPOSITORY as symbol]: repository } },
  })
}

describe('SessionEndedBanner (#650, S2)', () => {
  afterEach(() => {
    document.body.innerHTML = ''
  })

  it("n'affiche rien tant que la session n'a pas pris fin", async () => {
    const repository = createRepository(async () => sessionFor(null))
    await resetToSignedIn(repository)
    const wrapper = await mountBanner('/fr/admin/case-studies', repository)

    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('annonce la fin de session dans une alerte, avec un lien de reconnexion qui ramène à la page courante', async () => {
    const repository = createRepository(async () => sessionFor(null))
    await resetToSignedIn(repository)
    const wrapper = await mountBanner('/fr/admin/case-studies', repository)

    markSessionExpired()
    await flushPromises()

    const alert = wrapper.get('[role="alert"]')
    expect(alert.text()).toContain('Votre session a pris fin')
    const link = alert.get('a')
    expect(link.text()).toContain('Se reconnecter')
    expect(link.attributes('href')).toBe('/fr/login?redirect=/fr/admin/case-studies&expired=1')
    wrapper.unmount()
  })

  it("ouvre la connexion dans un autre onglet : l'onglet courant garde sa saisie, le cookie est partagé", async () => {
    const repository = createRepository(async () => sessionFor(null))
    await resetToSignedIn(repository)
    const wrapper = await mountBanner('/en/admin/case-studies', repository, 'en')

    markSessionExpired()
    await flushPromises()

    const link = wrapper.get('[role="alert"] a')
    expect(link.attributes('target')).toBe('_blank')
    expect(link.attributes('rel')).toContain('noopener')
    // L'ouverture dans un autre onglet est dite à qui ne voit pas l'icône ou le comportement.
    expect(link.text()).toContain('new tab')
    expect(link.attributes('href')).toContain('/en/login?redirect=')
    wrapper.unmount()
  })

  it("ne prend pas le focus quand il apparaît (la personne est peut-être en train de saisir)", async () => {
    const repository = createRepository(async () => sessionFor(null))
    await resetToSignedIn(repository)
    const field = document.createElement('textarea')
    document.body.appendChild(field)
    field.focus()
    const wrapper = await mountBanner('/fr/admin/case-studies', repository)

    markSessionExpired()
    await flushPromises()

    expect(document.activeElement).toBe(field)
    wrapper.unmount()
  })

  it("au retour sur l'onglet, relit la session : un compte retrouvé efface le bandeau", async () => {
    let signedIn = false
    const repository = createRepository(async () =>
      sessionFor(signedIn ? { username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] } : null),
    )
    await resetToSignedIn(repository)
    const wrapper = await mountBanner('/fr/admin/case-studies', repository)
    markSessionExpired()
    await flushPromises()

    // Pas encore reconnecté : le retour sur l'onglet relit, et le bandeau reste.
    window.dispatchEvent(new Event('focus'))
    await flushPromises()
    expect(wrapper.find('[role="alert"]').exists()).toBe(true)

    signedIn = true
    window.dispatchEvent(new Event('focus'))
    await flushPromises()
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it("ne relit pas la session au retour sur l'onglet tant que la session n'a pas pris fin", async () => {
    const repository = createRepository(async () => sessionFor(null))
    await resetToSignedIn(repository)
    const wrapper = await mountBanner('/fr/admin/case-studies', repository)

    window.dispatchEvent(new Event('focus'))
    await flushPromises()

    expect(repository.me).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it("retire son écouteur au démontage", async () => {
    const repository = createRepository(async () => sessionFor(null))
    await resetToSignedIn(repository)
    const wrapper = await mountBanner('/fr/admin/case-studies', repository)
    markSessionExpired()
    wrapper.unmount()

    window.dispatchEvent(new Event('focus'))
    await flushPromises()

    expect(repository.me).not.toHaveBeenCalled()
  })

  it("n'a aucune violation d'accessibilité une fois affiché", async () => {
    const repository = createRepository(async () => sessionFor(null))
    await resetToSignedIn(repository)
    const wrapper = await mountBanner('/fr/admin/case-studies', repository)
    markSessionExpired()
    await flushPromises()

    await expectNoAccessibilityViolation(wrapper)
    wrapper.unmount()
  })
})
