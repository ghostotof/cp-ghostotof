import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import TechnologiesSection from '../../../src/presentation/sections/TechnologiesSection.vue'
import type { Technology } from '../../../src/domain/portfolio/entities/Technology'
import { createAppI18n } from '../../../src/presentation/i18n'

const featuredTechnologies: readonly Technology[] = [
  { name: 'Symfony', description: 'Framework PHP', iconKey: 'symfony' },
  { name: 'Docker', description: 'Conteneurisation', iconKey: 'docker' },
]

const additionalTechnologies: readonly Technology[] = [
  { name: 'API Platform' },
  { name: 'Nginx' },
  { name: 'Linux' },
]

/**
 * Un routeur est nécessaire depuis que la section renvoie vers /stack : sans
 * lui, Vue se contente d'un avertissement en console et le lien n'est pas rendu
 * — le test passerait sans rien vérifier.
 */
function createTestRouter(): Router {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/:locale(fr|en)', name: 'home', component: { template: '<div />' } },
      { path: '/:locale(fr|en)/stack', name: 'stack', component: { template: '<div />' } },
    ],
  })
}

function mountSection() {
  return mount(TechnologiesSection, {
    props: { featuredTechnologies, additionalTechnologies },
    global: { plugins: [createAppI18n(), createTestRouter()] },
  })
}

describe('TechnologiesSection', () => {
  it('a pour ancre #technologies', () => {
    const wrapper = mountSection()

    expect(wrapper.get('section').attributes('id')).toBe('technologies')
  })

  /**
   * Le pont entre les deux lectures : cette section dit ce que je maîtrise, la
   * page dit dans quelles versions cela tourne. Depuis que le menu ne pointe
   * plus vers l'ancre (décision D8), c'est aussi le chemin qui mène le lecteur
   * de l'une à l'autre.
   */
  it('renvoie vers la page de veille technique', () => {
    const wrapper = mountSection()

    const link = wrapper.get('a[href="/fr/stack"]')

    expect(link.text()).toContain('versions')
  })

  it('expose le titre de section comme un h2 (navigation par titres)', () => {
    const wrapper = mountSection()

    expect(wrapper.get('h2').text()).toContain('Technologies')
  })

  it('rend une carte par technologie phare', () => {
    const wrapper = mountSection()

    expect(wrapper.text()).toContain('Symfony')
    expect(wrapper.text()).toContain('Framework PHP')
    expect(wrapper.text()).toContain('Docker')
  })

  it('met en avant la carte Symfony uniquement', () => {
    const wrapper = mountSection()

    const highlighted = wrapper.findAll('.card-portfolio--highlighted')
    expect(highlighted).toHaveLength(1)
    expect(highlighted[0]?.text()).toContain('Symfony')
  })

  it('rend chaque technologie additionnelle dans la liste "ET AUSSI"', () => {
    const wrapper = mountSection()

    for (const technology of additionalTechnologies) {
      expect(wrapper.text()).toContain(technology.name)
    }
  })
})
