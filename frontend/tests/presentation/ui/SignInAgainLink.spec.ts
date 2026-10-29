import { describe, expect, it } from 'vitest'
import { mount, type VueWrapper } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createAppI18n } from '../../../src/presentation/i18n'
import SignInAgainLink from '../../../src/presentation/ui/SignInAgainLink.vue'
import { expectNoAccessibilityViolation } from '../../support/axe'

/**
 * Le lien de reconnexion unique (#748, R6, Std3 et Std7) : un nouvel onglet qui ramène à la page courante, dit au
 * texte (WCAG 3.2.5) et à l'œil (l'icône), rendu par le bandeau, l'en-tête, « Mes connexions MCP » et l'assistant.
 */

async function mountLink(path: string, locale: 'fr' | 'en', attrs: Record<string, string> = {}): Promise<VueWrapper> {
  const i18n = createAppI18n()
  i18n.global.locale.value = locale
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      // Une route protégée : seul son chemin part dans `redirect` (F8) ; le consentement est dans le même onglet.
      { path: '/:locale/mcp-connections', component: { template: '<div />' }, meta: { requiresAuth: true } },
      { path: '/:locale/contact', component: { template: '<div />' } },
      {
        path: '/:locale/oauth/consent',
        component: { template: '<div />' },
        meta: { requiresAuth: true, signInInSameTab: true },
      },
      { path: '/:locale/login', name: 'login', component: { template: '<div />' } },
    ],
  })
  await router.push(path)
  await router.isReady()

  return mount(SignInAgainLink, {
    attrs,
    slots: { default: 'fr' === locale ? 'Se reconnecter' : 'Sign in again' },
    global: { plugins: [router, i18n] },
  })
}

describe('SignInAgainLink', () => {
  it('mène à la connexion avec le retour ici et expired=1, dans un nouvel onglet', async () => {
    const wrapper = await mountLink('/fr/mcp-connections', 'fr')

    const link = wrapper.get('a')
    expect(link.attributes('href')).toBe('/fr/login?redirect=/fr/mcp-connections&expired=1')
    expect(link.attributes('target')).toBe('_blank')
    expect(link.attributes('rel')).toBe('noopener')
  })

  it("dit l'ouverture dans un nouvel onglet au texte, en français", async () => {
    const wrapper = await mountLink('/fr/mcp-connections', 'fr')

    expect(wrapper.get('a').text()).toBe("Se reconnecter (s'ouvre dans un nouvel onglet)")
  })

  it("dit l'ouverture dans un nouvel onglet au texte, en anglais, et porte la langue de la route", async () => {
    const wrapper = await mountLink('/en/mcp-connections', 'en')

    expect(wrapper.get('a').text()).toBe('Sign in again (opens in a new tab)')
    expect(wrapper.get('a').attributes('href')).toMatch(/^\/en\/login\?/)
  })

  it("montre l'ouverture aux yeux : une icône cachée aux lecteurs d'écran", async () => {
    const wrapper = await mountLink('/fr/mcp-connections', 'fr')

    expect(wrapper.find('a svg[aria-hidden="true"]').exists()).toBe(true)
  })

  it('pose une classe passée sur le lien lui-même (racine unique)', async () => {
    const wrapper = await mountLink('/fr/mcp-connections', 'fr', { class: 'btn btn-outline-light btn-sm' })

    expect(wrapper.element.tagName).toBe('A')
    expect(wrapper.get('a').classes()).toEqual(expect.arrayContaining(['btn', 'btn-outline-light', 'btn-sm']))
  })

  it("sur une route publique : pas de redirect, le chemin de la page ne part pas dans l'URL (F8)", async () => {
    const wrapper = await mountLink('/fr/contact?x=1', 'fr')

    expect(wrapper.get('a').attributes('href')).toBe('/fr/login?expired=1')
  })

  it('route signInInSameTab : même onglet, ni texte caché ni icône, le retour ici et expired=1', async () => {
    const wrapper = await mountLink('/fr/oauth/consent', 'fr')

    const link = wrapper.get('a')
    expect(link.attributes('href')).toBe('/fr/login?redirect=/fr/oauth/consent&expired=1')
    expect(link.attributes('target')).toBeUndefined()
    expect(link.attributes('rel')).toBeUndefined()
    expect(link.text()).toBe('Se reconnecter')
    expect(link.find('.visually-hidden').exists()).toBe(false)
    expect(link.find('svg').exists()).toBe(false)
  })

  it("n'a aucune violation d'accessibilité détectable", async () => {
    const wrapper = await mountLink('/fr/mcp-connections', 'fr')

    await expectNoAccessibilityViolation(wrapper)
  })
})
