import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
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

function mountSection() {
  return mount(TechnologiesSection, {
    props: { featuredTechnologies, additionalTechnologies },
    global: { plugins: [createAppI18n()] },
  })
}

describe('TechnologiesSection', () => {
  it('a pour ancre #technologies', () => {
    const wrapper = mountSection()

    expect(wrapper.get('section').attributes('id')).toBe('technologies')
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
