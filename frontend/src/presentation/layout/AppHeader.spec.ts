import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import AppHeader from './AppHeader.vue'
import type { SiteIdentity } from '../../domain/portfolio/entities/SiteIdentity'
import type { NavigationLink } from '../../domain/portfolio/entities/NavigationLink'

const siteIdentity: SiteIdentity = {
  brandName: 'CP-Ghostotof',
  cvDownloadLabel: 'Télécharger mon CV',
  cvDownloadHref: '/cv.pdf',
}

const navigationLinks: readonly NavigationLink[] = [
  { label: 'Accueil', to: '/', isEnabled: true },
  { label: 'À propos', to: '/about', isEnabled: true },
  { label: 'Expériences', to: '/#experiences', isEnabled: false },
]

const StubPage = { template: '<div />' }

/**
 * AppHeader dépend de Vue Router (RouterLink + route courante pour l'état actif) :
 * on lui fournit un vrai routeur en mémoire plutôt que des stubs, pour vérifier le
 * comportement réel de résolution des liens et de détection de la page active.
 */
async function mountHeader(initialPath = '/') {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: StubPage },
      { path: '/about', name: 'about', component: StubPage },
    ],
  })
  await router.push(initialPath)
  await router.isReady()

  return mount(AppHeader, {
    props: { siteIdentity, navigationLinks },
    global: { plugins: [router] },
  })
}

describe('AppHeader', () => {
  it("affiche le nom de marque et le lien de téléchargement du CV", async () => {
    const wrapper = await mountHeader()

    expect(wrapper.text()).toContain('CP-Ghostotof')
    expect(wrapper.text()).toContain('Télécharger mon CV')
    expect(wrapper.findAll(`a[href="/cv.pdf"]`).length).toBeGreaterThan(0)
  })

  it('rend un lien navigable (RouterLink) pour chaque lien de navigation actif', async () => {
    const wrapper = await mountHeader()

    const activeLink = wrapper.get('a[href="/about"]')
    expect(activeLink.text()).toBe('À propos')
    expect(activeLink.attributes('aria-disabled')).toBe('false')
  })

  it('désactive les liens de navigation non activés (pas de href, aria-disabled)', async () => {
    const wrapper = await mountHeader()

    const disabledLinks = wrapper.findAll('.nav-link-portfolio--disabled')
    const experiencesLink = disabledLinks.find((link) => link.text() === 'Expériences')

    expect(experiencesLink).toBeDefined()
    expect(experiencesLink?.attributes('href')).toBeUndefined()
    expect(experiencesLink?.attributes('aria-disabled')).toBe('true')
  })

  it('marque le lien Accueil comme actif sur la page d\'accueil', async () => {
    const wrapper = await mountHeader('/')

    const homeLink = wrapper.get('nav a[href="/"]')
    expect(homeLink.classes()).toContain('nav-link-portfolio--active')
  })

  it('marque le lien À propos comme actif sur la page /about', async () => {
    const wrapper = await mountHeader('/about')

    const aboutLink = wrapper.get('nav a[href="/about"]')
    expect(aboutLink.classes()).toContain('nav-link-portfolio--active')

    const homeLink = wrapper.get('nav a[href="/"]')
    expect(homeLink.classes()).not.toContain('nav-link-portfolio--active')
  })

  it("le menu mobile est fermé par défaut puis s'ouvre au clic sur le bouton menu", async () => {
    const wrapper = await mountHeader()

    expect(wrapper.find('#mobile-nav').exists()).toBe(false)

    await wrapper.get('button[aria-controls="mobile-nav"]').trigger('click')

    expect(wrapper.find('#mobile-nav').exists()).toBe(true)
  })

  it('le menu mobile se referme après un clic sur un lien', async () => {
    const wrapper = await mountHeader()

    await wrapper.get('button[aria-controls="mobile-nav"]').trigger('click')
    expect(wrapper.find('#mobile-nav').exists()).toBe(true)

    await wrapper.get('#mobile-nav a[href="/about"]').trigger('click')

    expect(wrapper.find('#mobile-nav').exists()).toBe(false)
  })
})
