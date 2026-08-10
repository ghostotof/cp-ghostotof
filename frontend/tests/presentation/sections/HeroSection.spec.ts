import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import HeroSection from '../../../src/presentation/sections/HeroSection.vue'
import type { HeroContent } from '../../../src/domain/portfolio/entities/HeroContent'
import { createAppI18n } from '../../../src/presentation/i18n'

const content: HeroContent = {
  eyebrow: 'Développeur Web Senior',
  titleLead: 'Je construis des applications',
  titleAccent: 'robustes, performantes et évolutives.',
  description: 'Développeur passionné par la création de solutions web modernes et maintenables.',
  callsToAction: [
    { label: 'Découvrir mon approche', href: '#technologies', variant: 'primary', iconKey: 'arrow-right' },
    { label: 'Me contacter', href: '#contact', variant: 'secondary', iconKey: 'message-circle' },
  ],
  highlights: [
    { label: 'Code propre', iconKey: 'code' },
    { label: 'Sécurité', iconKey: 'shield' },
  ],
}

function mountSection() {
  return mount(HeroSection, { props: { content }, global: { plugins: [createAppI18n()] } })
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
    expect(links[0]?.attributes('href')).toBe('#technologies')
    expect(links[1]?.attributes('href')).toBe('#contact')
  })

  it('rend un élément de liste par highlight', () => {
    const wrapper = mountSection()

    const items = wrapper.findAll('li')
    expect(items).toHaveLength(content.highlights.length)
    expect(items[0]?.text()).toContain('Code propre')
  })
})
