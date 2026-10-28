import { afterEach, describe, expect, it } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import AccountMenu from '../../../src/presentation/layout/AccountMenu.vue'
import { createAppI18n } from '../../../src/presentation/i18n'
import { expectNoAccessibilityViolation } from '../../support/axe'

enableAutoUnmount(afterEach)

const StubPage = { template: '<div />' }

interface Options {
  isSuperAdmin?: boolean
  isDownloadingCv?: boolean
  locale?: 'fr' | 'en'
}

/**
 * Le menu est monté rattaché au document, avec un bouton « voisin » à côté :
 * le focus et le clic extérieur n'existent pas dans un arbre détaché.
 */
async function mountMenu(options: Options = {}) {
  const locale = options.locale ?? 'fr'
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/:locale(fr|en)', name: 'home', component: StubPage },
      { path: '/:locale(fr|en)/assistant', name: 'assistant', component: StubPage },
      { path: '/:locale(fr|en)/admin', name: 'admin', component: StubPage },
      { path: '/:locale(fr|en)/mcp-connections', name: 'mcp-connections', component: StubPage },
    ],
  })
  await router.push(`/${locale}`)
  await router.isReady()

  const i18n = createAppI18n()
  i18n.global.locale.value = locale

  const host = document.createElement('div')
  const outside = document.createElement('button')
  outside.textContent = 'dehors'
  document.body.append(host, outside)

  const wrapper = mount(AccountMenu, {
    attachTo: host,
    props: { homeLink: `/${locale}`, isSuperAdmin: options.isSuperAdmin ?? false, isDownloadingCv: options.isDownloadingCv ?? false },
    global: { plugins: [router, i18n] },
  })

  return {
    wrapper,
    router,
    outside,
    cleanup: () => {
      host.remove()
      outside.remove()
    },
  }
}

const TOGGLE = 'button[aria-controls="account-menu-desktop"]'

function items(wrapper: Awaited<ReturnType<typeof mountMenu>>['wrapper']): string[] {
  return wrapper.get('#account-menu-desktop').findAll('a, button').map((item) => item.text())
}

describe('AccountMenu (menu du compte, écran large)', () => {
  it('est fermé par défaut : un bouton nommé, aria-expanded faux, aria-controls vers la liste, qui n\'existe pas encore', async () => {
    const { wrapper, cleanup } = await mountMenu()

    const toggle = wrapper.get(TOGGLE)
    expect(toggle.text()).toBe('Mon compte')
    expect(toggle.attributes('aria-expanded')).toBe('false')
    expect(toggle.attributes('aria-controls')).toBe('account-menu-desktop')
    expect(wrapper.find('#account-menu-desktop').exists()).toBe(false)
    cleanup()
  })

  it('suit le motif « disclosure », pas « menu » : ni aria-haspopup ni rôle menu', async () => {
    const { wrapper, cleanup } = await mountMenu()

    await wrapper.get(TOGGLE).trigger('click')

    expect(wrapper.get(TOGGLE).attributes('aria-haspopup')).toBeUndefined()
    expect(wrapper.find('[role="menu"], [role="menuitem"]').exists()).toBe(false)
    // Une liste de liens ordinaires.
    expect(wrapper.get('#account-menu-desktop').element.tagName).toBe('UL')
    cleanup()
  })

  it('s\'ouvre au clic puis se referme au second : aria-expanded suit, la liste est celle d\'aria-controls', async () => {
    const { wrapper, cleanup } = await mountMenu()

    await wrapper.get(TOGGLE).trigger('click')
    expect(wrapper.get(TOGGLE).attributes('aria-expanded')).toBe('true')
    expect(wrapper.find('#account-menu-desktop').exists()).toBe(true)

    await wrapper.get(TOGGLE).trigger('click')
    expect(wrapper.get(TOGGLE).attributes('aria-expanded')).toBe('false')
    expect(wrapper.find('#account-menu-desktop').exists()).toBe(false)
    cleanup()
  })

  it('palier de confiance : Assistant, CV, Mes connexions MCP, Déconnexion', async () => {
    const { wrapper, cleanup } = await mountMenu()

    await wrapper.get(TOGGLE).trigger('click')

    expect(items(wrapper)).toEqual(['Assistant', 'Télécharger mon CV', 'Mes connexions MCP', 'Déconnexion'])
    expect(wrapper.get('#account-menu-desktop a[href="/fr/assistant"]').text()).toBe('Assistant')
    expect(wrapper.get('#account-menu-desktop a[href="/fr/mcp-connections"]').text()).toBe('Mes connexions MCP')
    cleanup()
  })

  it('ROLE_SUPER : Administration à la place du CV', async () => {
    const { wrapper, cleanup } = await mountMenu({ isSuperAdmin: true })

    await wrapper.get(TOGGLE).trigger('click')

    expect(items(wrapper)).toEqual(['Assistant', 'Administration', 'Mes connexions MCP', 'Déconnexion'])
    expect(wrapper.get('#account-menu-desktop a[href="/fr/admin"]').text()).toBe('Administration')
    cleanup()
  })

  it('en anglais, les libellés suivent la langue et les liens gardent le préfixe /en', async () => {
    const { wrapper, cleanup } = await mountMenu({ locale: 'en' })

    expect(wrapper.get(TOGGLE).text()).toBe('My account')
    await wrapper.get(TOGGLE).trigger('click')

    expect(items(wrapper)).toEqual(['Assistant', 'Download my CV', 'My MCP connections', 'Logout'])
    expect(wrapper.find('#account-menu-desktop a[href="/en/mcp-connections"]').exists()).toBe(true)
    cleanup()
  })

  it('le CV est désactivé pendant son téléchargement', async () => {
    const { wrapper, cleanup } = await mountMenu({ isDownloadingCv: true })

    await wrapper.get(TOGGLE).trigger('click')

    const cv = wrapper.get('#account-menu-desktop').findAll('button').find((button) => 'Télécharger mon CV' === button.text())
    expect(cv?.attributes('disabled')).toBeDefined()
    cleanup()
  })

  it('Échap referme le menu et rend le focus au bouton', async () => {
    const { wrapper, cleanup } = await mountMenu()
    await wrapper.get(TOGGLE).trigger('click')
    // Le focus est dans la liste, comme après une tabulation depuis le bouton.
    ;(wrapper.get('#account-menu-desktop a').element as HTMLElement).focus()

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await flushPromises()

    expect(wrapper.find('#account-menu-desktop').exists()).toBe(false)
    expect(wrapper.get(TOGGLE).attributes('aria-expanded')).toBe('false')
    expect(document.activeElement).toBe(wrapper.get(TOGGLE).element)
    cleanup()
  })

  it('Échap menu fermé ne prend pas le focus', async () => {
    const { wrapper, outside, cleanup } = await mountMenu()
    outside.focus()

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await flushPromises()

    expect(wrapper.find('#account-menu-desktop').exists()).toBe(false)
    expect(document.activeElement).toBe(outside)
    cleanup()
  })

  it('un clic en dehors du menu le referme, sans lui rendre le focus', async () => {
    const { wrapper, outside, cleanup } = await mountMenu()
    await wrapper.get(TOGGLE).trigger('click')

    outside.focus()
    outside.click()
    await flushPromises()

    expect(wrapper.find('#account-menu-desktop').exists()).toBe(false)
    expect(document.activeElement).toBe(outside)
    cleanup()
  })

  it('un clic dans le menu, hors action, ne le referme pas', async () => {
    const { wrapper, cleanup } = await mountMenu()
    await wrapper.get(TOGGLE).trigger('click')

    ;(wrapper.get('#account-menu-desktop').element as HTMLElement).click()
    await flushPromises()

    expect(wrapper.find('#account-menu-desktop').exists()).toBe(true)
    cleanup()
  })

  it('la navigation referme le menu', async () => {
    const { wrapper, router, cleanup } = await mountMenu()
    await wrapper.get(TOGGLE).trigger('click')

    await wrapper.get('#account-menu-desktop a[href="/fr/mcp-connections"]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('mcp-connections')
    expect(wrapper.find('#account-menu-desktop').exists()).toBe(false)
    cleanup()
  })

  it('une navigation venue d\'ailleurs (retour arrière, lien de la page) referme aussi le menu', async () => {
    const { wrapper, router, cleanup } = await mountMenu()
    await wrapper.get(TOGGLE).trigger('click')

    await router.push('/fr/assistant')
    await flushPromises()

    expect(wrapper.find('#account-menu-desktop').exists()).toBe(false)
    cleanup()
  })

  it('un lien vers la page où l\'on est déjà referme quand même le menu', async () => {
    const { wrapper, router, cleanup } = await mountMenu()
    await router.push('/fr/mcp-connections')
    await wrapper.get(TOGGLE).trigger('click')

    await wrapper.get('#account-menu-desktop a[href="/fr/mcp-connections"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('#account-menu-desktop').exists()).toBe(false)
    cleanup()
  })

  it('« Déconnexion » et « Télécharger mon CV » émettent leur action et referment le menu', async () => {
    const { wrapper, cleanup } = await mountMenu()
    await wrapper.get(TOGGLE).trigger('click')

    const buttons = wrapper.get('#account-menu-desktop').findAll('button')
    await buttons.find((button) => 'Déconnexion' === button.text())?.trigger('click')
    expect(wrapper.emitted('logout')).toHaveLength(1)
    expect(wrapper.find('#account-menu-desktop').exists()).toBe(false)

    await wrapper.get(TOGGLE).trigger('click')
    await wrapper.get('#account-menu-desktop').findAll('button').find((button) => 'Télécharger mon CV' === button.text())?.trigger('click')
    expect(wrapper.emitted('downloadCv')).toHaveLength(1)
    expect(wrapper.find('#account-menu-desktop').exists()).toBe(false)
    cleanup()
  })

  it('l\'entrée de la page courante porte aria-current="page"', async () => {
    const { wrapper, router, cleanup } = await mountMenu()
    await router.push('/fr/mcp-connections')
    await wrapper.get(TOGGLE).trigger('click')

    expect(wrapper.get('#account-menu-desktop a[href="/fr/mcp-connections"]').attributes('aria-current')).toBe('page')
    expect(wrapper.get('#account-menu-desktop a[href="/fr/assistant"]').attributes('aria-current')).toBeUndefined()
    cleanup()
  })

  it('menu fermé comme ouvert : aucune violation d\'accessibilité détectable', async () => {
    const { wrapper, cleanup } = await mountMenu()
    await expectNoAccessibilityViolation(wrapper)

    await wrapper.get(TOGGLE).trigger('click')
    await expectNoAccessibilityViolation(wrapper)
    cleanup()
  })

  it('ROLE_SUPER, menu ouvert : aucune violation d\'accessibilité détectable', async () => {
    const { wrapper, cleanup } = await mountMenu({ isSuperAdmin: true })

    await wrapper.get(TOGGLE).trigger('click')

    await expectNoAccessibilityViolation(wrapper)
    cleanup()
  })
})
