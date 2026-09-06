import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import HeroSection from '../../../src/presentation/sections/HeroSection.vue'
import type { HeroContent } from '../../../src/domain/portfolio/entities/HeroContent'
import { createAppI18n } from '../../../src/presentation/i18n'

const content: HeroContent = {
  eyebrow: 'Développeur Web Senior',
  titleLead: 'Je construis des applications',
  titleAccent: 'robustes, performantes et évolutives.',
  description: 'Développeur passionné par la création de solutions web modernes et maintenables.',
  callsToAction: [
    {
      label: 'Lire le code sur GitHub',
      href: 'https://github.com/ghostotof/cp-ghostotof',
      variant: 'primary',
      iconKey: 'github',
      isExternal: true,
    },
    { label: 'Comment il est construit', href: '/fr/about', variant: 'secondary', iconKey: 'arrow-right' },
  ],
  highlights: [
    { label: 'Code propre', iconKey: 'code' },
    { label: 'Sécurité', iconKey: 'shield' },
  ],
}

/**
 * Un routeur est nécessaire : BaseButton rend les appels à action internes en
 * RouterLink, pour éviter le rechargement complet du document.
 */
function mountSection() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/fr/about', component: { template: '<div />' } },
    ],
  })

  return mount(HeroSection, { props: { content }, global: { plugins: [router, createAppI18n()] } })
}

describe('HeroSection', () => {
  it('a pour ancre #hero', () => {
    const wrapper = mountSection()

    expect(wrapper.get('section').attributes('id')).toBe('hero')
  })

  it('rend le titre, la description et l\'eyebrow', () => {
    const wrapper = mountSection()

    expect(wrapper.text()).toContain(content.eyebrow)
    expect(wrapper.text()).toContain(content.titleLead)
    expect(wrapper.text()).toContain(content.titleAccent)
    expect(wrapper.text()).toContain(content.description)
  })

  it('rend un bouton par appel à action, avec le bon href', () => {
    const wrapper = mountSection()

    const links = wrapper.findAll('a.btn')
    expect(links).toHaveLength(content.callsToAction.length)
    expect(links[0]?.attributes('href')).toBe('https://github.com/ghostotof/cp-ghostotof')
    expect(links[1]?.attributes('href')).toBe('/fr/about')
  })

  it('transmet isExternal à BaseButton, qui isole la navigation sortante', () => {
    const wrapper = mountSection()

    const links = wrapper.findAll('a.btn')

    // Régression : sans la transmission de la propriété, le lien vers le dépôt
    // remplacerait le portfolio dans l'onglet courant au lieu de s'ouvrir à
    // côté — et perdrait la protection contre le tabnabbing.
    expect(links[0]?.attributes('target')).toBe('_blank')
    expect(links[0]?.attributes('rel')).toBe('noopener noreferrer')
    expect(links[1]?.attributes('target')).toBeUndefined()
  })

  it('rend un élément de liste par highlight', () => {
    const wrapper = mountSection()

    const items = wrapper.findAll('li')
    expect(items).toHaveLength(content.highlights.length)
    expect(items[0]?.text()).toContain('Code propre')
  })
})
