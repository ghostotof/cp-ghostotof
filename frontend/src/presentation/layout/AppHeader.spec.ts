import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import AppHeader from './AppHeader.vue'
import type { SiteIdentity } from '../../domain/portfolio/entities/SiteIdentity'
import type { NavigationLink } from '../../domain/portfolio/entities/NavigationLink'

const siteIdentity: SiteIdentity = {
  brandName: 'CP-Ghostotof',
  cvDownloadLabel: 'Télécharger mon CV',
  cvDownloadHref: '/cv.pdf',
}

const navigationLinks: readonly NavigationLink[] = [
  { label: 'Accueil', href: '#hero', isEnabled: true },
  { label: 'À propos', href: '#a-propos', isEnabled: true },
  { label: 'Expériences', href: '#experiences', isEnabled: false },
]

function mountHeader() {
  return mount(AppHeader, { props: { siteIdentity, navigationLinks } })
}

describe('AppHeader', () => {
  it("affiche le nom de marque et le lien de téléchargement du CV", () => {
    const wrapper = mountHeader()

    expect(wrapper.text()).toContain('CP-Ghostotof')
    expect(wrapper.text()).toContain('Télécharger mon CV')
    expect(wrapper.findAll(`a[href="/cv.pdf"]`).length).toBeGreaterThan(0)
  })

  it('rend un lien navigable pour chaque lien de navigation actif', () => {
    const wrapper = mountHeader()

    const activeLink = wrapper.get('a[href="#a-propos"]')
    expect(activeLink.text()).toBe('À propos')
    expect(activeLink.attributes('aria-disabled')).toBe('false')
  })

  it('désactive les liens de navigation non activés (pas de href, aria-disabled)', () => {
    const wrapper = mountHeader()

    const disabledLinks = wrapper.findAll('.nav-link-portfolio--disabled')
    const experiencesLink = disabledLinks.find((link) => link.text() === 'Expériences')

    expect(experiencesLink).toBeDefined()
    expect(experiencesLink?.attributes('href')).toBeUndefined()
    expect(experiencesLink?.attributes('aria-disabled')).toBe('true')
  })

  it('marque le lien Accueil comme actif', () => {
    const wrapper = mountHeader()

    const homeLink = wrapper.get('nav a[href="#hero"]')
    expect(homeLink.classes()).toContain('nav-link-portfolio--active')
  })

  it('le menu mobile est fermé par défaut puis s\'ouvre au clic sur le bouton menu', async () => {
    const wrapper = mountHeader()

    expect(wrapper.find('#mobile-nav').exists()).toBe(false)

    await wrapper.get('button[aria-controls="mobile-nav"]').trigger('click')

    expect(wrapper.find('#mobile-nav').exists()).toBe(true)
  })

  it('le menu mobile se referme après un clic sur un lien', async () => {
    const wrapper = mountHeader()

    await wrapper.get('button[aria-controls="mobile-nav"]').trigger('click')
    expect(wrapper.find('#mobile-nav').exists()).toBe(true)

    await wrapper.get('#mobile-nav a[href="#a-propos"]').trigger('click')

    expect(wrapper.find('#mobile-nav').exists()).toBe(false)
  })
})
