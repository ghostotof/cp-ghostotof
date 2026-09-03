import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import { nextTick } from 'vue'
import AdminLayout from '../../../src/presentation/layout/AdminLayout.vue'
import { createAppI18n } from '../../../src/presentation/i18n'

const StubPage = { template: '<div />' }

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
    attachTo: document.body,
    global: { plugins: [router, createAppI18n()] },
  })
  await wrapper.vm.$nextTick()

  return wrapper
}

function contentToggle(wrapper: Awaited<ReturnType<typeof mountLayout>>) {
  return wrapper.get('button[aria-haspopup="true"]')
}

describe('AdminLayout', () => {
  it('n\'a qu\'une seule barre de navigation de 1er niveau (plus de ligne de sous-menu séparée)', async () => {
    const wrapper = await mountLayout('/fr/admin/technologies')

    expect(wrapper.findAll('nav')).toHaveLength(1)
    wrapper.unmount()
  })

  it('« Contenu » est un bouton de menu, fermé par défaut', async () => {
    const wrapper = await mountLayout('/fr/admin/technologies')

    const toggle = contentToggle(wrapper)
    expect(toggle.text()).toBe('Contenu')
    expect(toggle.attributes('aria-expanded')).toBe('false')
    expect(wrapper.findAll('.dropdown-item')).toHaveLength(0)

    wrapper.unmount()
  })

  it('un clic sur « Contenu » ouvre le menu déroulant avec les 4 liens de section', async () => {
    const wrapper = await mountLayout('/fr/admin/technologies')

    await contentToggle(wrapper).trigger('click')

    expect(contentToggle(wrapper).attributes('aria-expanded')).toBe('true')
    const items = wrapper.findAll('.dropdown-item')
    expect(items.map((item) => item.text())).toEqual(['Technologies', 'À propos', 'Qualité', 'Statistiques'])

    wrapper.unmount()
  })

  it('un clic sur un lien du menu referme le menu', async () => {
    const wrapper = await mountLayout('/fr/admin/technologies')

    await contentToggle(wrapper).trigger('click')
    await wrapper.get('.dropdown-item').trigger('click')

    expect(contentToggle(wrapper).attributes('aria-expanded')).toBe('false')
    expect(wrapper.findAll('.dropdown-item')).toHaveLength(0)

    wrapper.unmount()
  })

  it('la touche Échap referme le menu', async () => {
    const wrapper = await mountLayout('/fr/admin/technologies')

    await contentToggle(wrapper).trigger('click')
    expect(contentToggle(wrapper).attributes('aria-expanded')).toBe('true')

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    await nextTick()

    expect(contentToggle(wrapper).attributes('aria-expanded')).toBe('false')
    wrapper.unmount()
  })

  it('un clic en dehors du menu le referme', async () => {
    const wrapper = await mountLayout('/fr/admin/technologies')

    await contentToggle(wrapper).trigger('click')
    expect(contentToggle(wrapper).attributes('aria-expanded')).toBe('true')

    document.body.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await nextTick()

    expect(contentToggle(wrapper).attributes('aria-expanded')).toBe('false')
    wrapper.unmount()
  })

  it('sur une route de contenu : « Contenu » porte le style actif et, à l\'ouverture, la section courante est marquée aria-current', async () => {
    const wrapper = await mountLayout('/fr/admin/quality')

    expect(contentToggle(wrapper).classes()).toContain('btn-gradient')

    await contentToggle(wrapper).trigger('click')
    const active = wrapper.findAll('.dropdown-item').find((item) => item.text() === 'Qualité')
    expect(active?.attributes('aria-current')).toBe('page')

    wrapper.unmount()
  })

  it('sur « Utilisateurs » : l\'onglet Utilisateurs est courant et « Contenu » n\'est pas actif', async () => {
    const wrapper = await mountLayout('/fr/admin/users')

    const usersTab = wrapper.get('a[aria-current="page"]')
    expect(usersTab.text()).toBe('Utilisateurs')
    expect(contentToggle(wrapper).classes()).not.toContain('btn-gradient')
    expect(contentToggle(wrapper).classes()).toContain('btn-outline-light')

    wrapper.unmount()
  })

  it('le menu déroulant porte un aria-label distinct de la navigation', async () => {
    const wrapper = await mountLayout('/fr/admin/stats')

    await contentToggle(wrapper).trigger('click')

    expect(wrapper.get('nav').attributes('aria-label')).toBe('Navigation d\'administration')
    expect(wrapper.get('.dropdown-menu').attributes('aria-label')).toBe('Navigation du contenu')

    wrapper.unmount()
  })
})
