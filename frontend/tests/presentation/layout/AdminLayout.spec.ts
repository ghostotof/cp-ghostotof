import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import AdminLayout from '../../../src/presentation/layout/AdminLayout.vue'
import { createAppI18n } from '../../../src/presentation/i18n'

const StubPage = { template: '<div />' }

/**
 * AdminLayout dépend de Vue Router (RouterLink + route courante pour l'état
 * actif des onglets) et de vue-i18n (libellés) : on lui fournit de vraies
 * instances plutôt que des stubs, comme AppHeader.spec, pour vérifier le
 * comportement réel de regroupement des sections.
 */
/**
 * AdminLayout est monté directement (comme AppHeader.spec) : les routes sont
 * déclarées à plat, sans AdminLayout comme composant parent, pour éviter que
 * son propre `<RouterView>` ne re-rende le layout de façon récursive.
 */
function createTestRouter(): Router {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/:locale(fr|en)/admin/technologies', name: 'admin-technologies', component: StubPage },
      { path: '/:locale(fr|en)/admin/about', name: 'admin-about', component: StubPage },
      { path: '/:locale(fr|en)/admin/quality', name: 'admin-quality', component: StubPage },
      { path: '/:locale(fr|en)/admin/stats', name: 'admin-stats', component: StubPage },
      { path: '/:locale(fr|en)/admin/users', name: 'admin-users', component: StubPage },
    ],
  })
}

async function mountLayout(initialPath: string) {
  const router = createTestRouter()
  await router.push(initialPath)
  await router.isReady()

  const wrapper = mount(AdminLayout, {
    global: { plugins: [router, createAppI18n()] },
  })
  await wrapper.vm.$nextTick()

  return wrapper
}

describe('AdminLayout', () => {
  it('regroupe les sections de contenu sous un onglet « Contenu », frère de « Utilisateurs »', async () => {
    const wrapper = await mountLayout('/fr/admin/technologies')

    const primaryNav = wrapper.get(`nav[aria-label="${'Navigation d\'administration'}"]`)
    const primaryLinks = primaryNav.findAll('a')

    expect(primaryLinks).toHaveLength(2)
    expect(primaryLinks[0].text()).toBe('Contenu')
    expect(primaryLinks[1].text()).toBe('Utilisateurs')
  })

  it('sur une route de contenu : l\'onglet « Contenu » est courant et la sous-navigation des 4 sections est affichée', async () => {
    const wrapper = await mountLayout('/fr/admin/quality')

    const primaryNav = wrapper.get('nav[aria-label="Navigation d\'administration"]')
    const contentTab = primaryNav.findAll('a')[0]
    expect(contentTab.attributes('aria-current')).toBe('page')

    const secondaryNav = wrapper.get('nav[aria-label="Navigation du contenu"]')
    const secondaryLinks = secondaryNav.findAll('a')
    expect(secondaryLinks.map((link) => link.text())).toEqual(['Technologies', 'À propos', 'Qualité', 'Statistiques'])

    const activeSub = secondaryLinks.find((link) => link.text() === 'Qualité')
    expect(activeSub?.attributes('aria-current')).toBe('page')
  })

  it('sur la route « Utilisateurs » : l\'onglet « Utilisateurs » est courant et aucune sous-navigation de contenu n\'est affichée', async () => {
    const wrapper = await mountLayout('/fr/admin/users')

    const primaryNav = wrapper.get('nav[aria-label="Navigation d\'administration"]')
    const usersTab = primaryNav.findAll('a')[1]
    expect(usersTab.attributes('aria-current')).toBe('page')
    expect(primaryNav.findAll('a')[0].attributes('aria-current')).toBeUndefined()

    expect(wrapper.find('nav[aria-label="Navigation du contenu"]').exists()).toBe(false)
  })

  it('donne un aria-label distinct aux deux navigations', async () => {
    const wrapper = await mountLayout('/fr/admin/stats')

    const labels = wrapper.findAll('nav').map((nav) => nav.attributes('aria-label'))
    expect(labels).toContain('Navigation d\'administration')
    expect(labels).toContain('Navigation du contenu')
    expect(new Set(labels).size).toBe(labels.length)
  })
})
