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
import fr from '../../../../src/infrastructure/i18n/locales/fr.json'
import en from '../../../../src/infrastructure/i18n/locales/en.json'

const StubPage = defineComponent({ setup: () => () => h('div') })

function createRepository(me: () => Promise<AuthSession>): AuthRepository {
  return {
    login: vi.fn(async () => ({ username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] })),
    logout: vi.fn(async () => undefined),
    hasWriteToken: () => true,
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

/**
 * Le retour de la session, tel que `AppLayout` le provoque au `focus` de la fenêtre (#748, R8) : le bandeau n'a plus
 * d'écouteur, il ne fait que suivre l'état. Ces tests déclenchent donc le retour par la relecture partagée.
 */
async function recheck(repository: AuthRepository): Promise<void> {
  const Probe = defineComponent({ setup: () => ({ auth: useAuth() }), template: '<div />' })
  const probe = mount(Probe, { global: { provide: { [AUTH_REPOSITORY as symbol]: repository } } })
  await probe.vm.auth.recheckEndedSession()
  probe.unmount()
  await flushPromises()
}

async function mountBanner(path: string, repository: AuthRepository, locale: 'fr' | 'en' = 'fr'): Promise<VueWrapper> {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      // `requiresAuth`, comme les vraies routes : sans lui le lien de connexion n'a pas de `redirect` (#748, R16, F8).
      { path: '/:locale(fr|en)/admin/case-studies', name: 'admin-case-studies', component: StubPage, meta: { requiresAuth: true } },
      { path: '/:locale(fr|en)/admin/technologies', name: 'admin-technologies', component: StubPage, meta: { requiresAuth: true } },
      { path: '/:locale(fr|en)/login', name: 'login', component: StubPage },
    ],
  })
  await router.push(path)
  await router.isReady()

  // La langue de l'interface suit celle de la route (le routeur de production la pose).
  const i18n = createAppI18n()
  i18n.global.locale.value = locale

  // Le bandeau a deux racines (l'alerte et la région `status`, #748 R9) : un hôte les réunit, comme la `<section>`
  // d'`AdminLayout` en production, pour que le wrapper les voie toutes les deux.
  const Host = defineComponent({
    components: { SessionEndedBanner },
    template: '<div><SessionEndedBanner /></div>',
  })

  return mount(Host, {
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
    expect(link.text()).toContain(fr.admin.sessionEnded.signInAgain)
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

  it("quand la relecture au retour retrouve un compte, le bandeau s'efface", async () => {
    let signedIn = false
    const repository = createRepository(async () =>
      sessionFor(signedIn ? { username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] } : null),
    )
    await resetToSignedIn(repository)
    const wrapper = await mountBanner('/fr/admin/case-studies', repository)
    markSessionExpired()
    await flushPromises()

    // Pas encore reconnecté : la relecture au retour sur l'onglet laisse le bandeau.
    await recheck(repository)
    expect(wrapper.find('[role="alert"]').exists()).toBe(true)

    signedIn = true
    await recheck(repository)
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it("n'écoute plus le focus lui-même : la revérification est celle d'AppLayout, une seule", async () => {
    const repository = createRepository(async () => sessionFor(null))
    await resetToSignedIn(repository)
    const wrapper = await mountBanner('/fr/admin/case-studies', repository)
    markSessionExpired()
    await flushPromises()

    window.dispatchEvent(new Event('focus'))
    await flushPromises()

    expect(repository.me).not.toHaveBeenCalled()
    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it("le bandeau est collant sous l'en-tête : l'alerte porte la classe qui le pose", async () => {
    const repository = createRepository(async () => sessionFor(null))
    await resetToSignedIn(repository)
    const wrapper = await mountBanner('/fr/admin/case-studies', repository)

    markSessionExpired()
    await flushPromises()

    expect(wrapper.get('[role="alert"]').classes()).toContain('session-ended-banner')
    wrapper.unmount()
  })

  it('une région role="status" existe dès le montage, vide', async () => {
    const repository = createRepository(async () => sessionFor(null))
    await resetToSignedIn(repository)
    const wrapper = await mountBanner('/fr/admin/case-studies', repository)

    const status = wrapper.get('[role="status"]')
    expect(status.text()).toBe('')
    expect(status.classes()).toContain('visually-hidden')
    wrapper.unmount()
  })

  it("au retour de la session, la région annonce « Session rétablie » et l'alerte disparaît", async () => {
    const repository = createRepository(async () => sessionFor({ username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] }))
    await resetToSignedIn(repository)
    const wrapper = await mountBanner('/fr/admin/case-studies', repository)
    markSessionExpired()
    await flushPromises()
    const statusBefore = wrapper.get('[role="status"]').element

    await recheck(repository)

    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    const status = wrapper.get('[role="status"]')
    // La région est la même que celle du montage : insérée avec son texte, elle risquerait de ne pas être annoncée.
    expect(status.element).toBe(statusBefore)
    expect(status.text()).toBe(fr.admin.sessionEnded.restored)
    expect(status.classes()).not.toContain('visually-hidden')
    wrapper.unmount()
  })

  it("un autre compte revient : « Session rétablie » n'est pas annoncée (la page n'est plus la sienne, R18)", async () => {
    // `resetToSignedIn` connecte `super` : c'est le compte dont la session finit. Revient un autre.
    const repository = createRepository(async () => sessionFor({ username: 'autre', roles: ['ROLE_SUPER', 'ROLE_USER'] }))
    await resetToSignedIn(repository)
    const wrapper = await mountBanner('/fr/admin/case-studies', repository)
    markSessionExpired()
    await flushPromises()

    await recheck(repository)

    // `AppLayout` rejoue la navigation et remonte la page ; ici, le bandeau ne promet rien : « vous pouvez enregistrer »
    // serait dit à propos d'une saisie qui n'est plus celle de ce compte.
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(wrapper.get('[role="status"]').text()).toBe('')
    wrapper.unmount()
  })

  it('le message de retour est dit dans la langue de la page', async () => {
    const repository = createRepository(async () => sessionFor({ username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] }))
    await resetToSignedIn(repository)
    const wrapper = await mountBanner('/en/admin/case-studies', repository, 'en')
    markSessionExpired()
    await flushPromises()

    await recheck(repository)

    expect(wrapper.get('[role="status"]').text()).toBe(en.admin.sessionEnded.restored)
    wrapper.unmount()
  })

  it('le message de retour se vide à la navigation suivante', async () => {
    const repository = createRepository(async () => sessionFor({ username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] }))
    await resetToSignedIn(repository)
    const wrapper = await mountBanner('/fr/admin/case-studies', repository)
    markSessionExpired()
    await flushPromises()
    await recheck(repository)
    expect(wrapper.get('[role="status"]').text()).not.toBe('')

    await wrapper.vm.$router.push('/fr/admin/technologies')
    await flushPromises()

    expect(wrapper.get('[role="status"]').text()).toBe('')
    wrapper.unmount()
  })

  it('une session qui reprend fin efface « Session rétablie »', async () => {
    const repository = createRepository(async () => sessionFor({ username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] }))
    await resetToSignedIn(repository)
    const wrapper = await mountBanner('/fr/admin/case-studies', repository)
    markSessionExpired()
    await flushPromises()
    await recheck(repository)
    expect(wrapper.get('[role="status"]').text()).toBe(fr.admin.sessionEnded.restored)

    // CR6 : le message disait « vous pouvez enregistrer » alors que le bandeau est de nouveau affiché.
    markSessionExpired()
    await flushPromises()

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    const status = wrapper.get('[role="status"]')
    expect(status.text()).toBe('')
    expect(status.classes()).toContain('visually-hidden')
    wrapper.unmount()
  })

  it("un nouveau refus réannonce sans retirer le lien qui a le focus", async () => {
    const repository = createRepository(async () => sessionFor(null))
    await resetToSignedIn(repository)
    const wrapper = await mountBanner('/fr/admin/case-studies', repository)
    markSessionExpired()
    await flushPromises()
    const alert = wrapper.get('[role="alert"]').element
    const link = wrapper.get('[role="alert"] a').element as HTMLElement
    const message = wrapper.find('[data-testid="admin-session-ended-message"]').exists()
      ? wrapper.get('[data-testid="admin-session-ended-message"]').element
      : null
    link.focus()
    expect(document.activeElement).toBe(link)

    // E (#748, R19) : la réannonce remplace le message, jamais l'élément qui porte le lien focalisé, sinon le
    // focus tombe sur `body` au milieu d'une navigation au clavier.
    markSessionExpired()
    await flushPromises()

    expect(document.activeElement).toBe(link)
    expect(wrapper.get('[role="alert"]').element).toBe(alert)
    expect(wrapper.get('[role="alert"] a').element).toBe(link)
    expect(wrapper.get('[data-testid="admin-session-ended-message"]').element).not.toBe(message)
    expect(wrapper.get('[data-testid="admin-session-ended-message"]').text()).toContain('Votre session a pris fin')
    wrapper.unmount()
  })

  it('plusieurs refus dans le même tick : un seul remplacement du message', async () => {
    const repository = createRepository(async () => sessionFor(null))
    await resetToSignedIn(repository)
    const wrapper = await mountBanner('/fr/admin/case-studies', repository)
    markSessionExpired()
    await flushPromises()
    const before = wrapper.get('[role="alert"]').element
    const inserted: Node[] = []
    const observer = new MutationObserver((records) => {
      for (const record of records) {
        record.addedNodes.forEach((node) => inserted.push(node))
      }
    })
    observer.observe(wrapper.element, { childList: true, subtree: true })

    markSessionExpired()
    markSessionExpired()
    markSessionExpired()
    await flushPromises()
    observer.disconnect()

    // Trois refus, un rendu : l'alerte reste, un seul message est inséré (R19, E).
    expect(wrapper.get('[role="alert"]').element).toBe(before)
    expect(
      inserted.filter((node) => node instanceof HTMLElement && 'admin-session-ended-message' === node.dataset.testid),
    ).toHaveLength(1)
    expect(inserted.filter((node) => node instanceof HTMLElement && 'alert' === node.getAttribute('role'))).toHaveLength(0)
    wrapper.unmount()
  })

  it("la hauteur publiée reste celle de l'alerte, qui n'est plus réinsérée par un refus de plus", async () => {
    const observed: Element[] = []
    const disconnected = vi.fn()
    vi.stubGlobal(
      'ResizeObserver',
      class {
        observe(element: Element): void {
          observed.push(element)
        }
        disconnect(): void {
          disconnected()
        }
      },
    )
    let wrapper: VueWrapper | null = null
    try {
      const repository = createRepository(async () => sessionFor(null))
      await resetToSignedIn(repository)
      wrapper = await mountBanner('/fr/admin/case-studies', repository)
      markSessionExpired()
      await flushPromises()
      expect(observed).toHaveLength(1)

      markSessionExpired()
      await flushPromises()

      // R19 (E) : l'alerte n'est plus remplacée, l'observateur garde le même élément (le message seul change).
      expect(observed).toHaveLength(1)
      expect(observed[0]).toBe(wrapper.get('[role="alert"]').element)
      expect(disconnected).not.toHaveBeenCalled()
    } finally {
      // Démonté avant de rendre le vrai `ResizeObserver` : un wrapper resté monté referait tourner la composable.
      wrapper?.unmount()
      vi.unstubAllGlobals()
    }
  })

  it("n'annonce pas de retour tant que la session n'a pas pris fin", async () => {
    const repository = createRepository(async () => sessionFor(null))
    await resetToSignedIn(repository)
    const wrapper = await mountBanner('/fr/admin/case-studies', repository)

    expect(wrapper.get('[role="status"]').text()).toBe('')
    wrapper.unmount()
  })

  it("le lien dit le nouvel onglet aussi aux yeux : une icône cachée aux lecteurs d'écran", async () => {
    const repository = createRepository(async () => sessionFor(null))
    await resetToSignedIn(repository)
    const wrapper = await mountBanner('/fr/admin/case-studies', repository)

    markSessionExpired()
    await flushPromises()

    const link = wrapper.get('[role="alert"] a')
    expect(link.find('svg[aria-hidden="true"]').exists()).toBe(true)
    expect(link.text()).toContain(`${fr.admin.sessionEnded.signInAgain} ${fr.common.opensInNewTab}`)
    wrapper.unmount()
  })

  it("n'a aucune violation d'accessibilité une fois la session rétablie", async () => {
    const repository = createRepository(async () => sessionFor({ username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] }))
    await resetToSignedIn(repository)
    const wrapper = await mountBanner('/fr/admin/case-studies', repository)
    markSessionExpired()
    await flushPromises()
    await recheck(repository)

    await expectNoAccessibilityViolation(wrapper)
    wrapper.unmount()
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
