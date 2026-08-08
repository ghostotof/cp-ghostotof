import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import AboutSection from './AboutSection.vue'
import type { AboutContent } from '../../domain/portfolio/entities/AboutContent'

const content: AboutContent = {
  eyebrow: 'À propos',
  titleLead: 'Un développeur guidé par',
  titleAccent: 'la rigueur et la recherche de sens.',
  paragraphs: ['Premier paragraphe de présentation.', 'Second paragraphe de présentation.'],
  values: [
    { title: 'Rigueur', description: 'Une exigence de qualité à chaque étape.', iconKey: 'shield' },
    { title: 'Curiosité', description: 'Une veille technologique continue.', iconKey: 'zap' },
  ],
}

describe('AboutSection', () => {
  it('a pour ancre #a-propos', () => {
    const wrapper = mount(AboutSection, { props: { content } })

    expect(wrapper.get('section').attributes('id')).toBe('a-propos')
  })

  it("rend l'eyebrow et le titre", () => {
    const wrapper = mount(AboutSection, { props: { content } })

    expect(wrapper.text()).toContain(content.eyebrow)
    expect(wrapper.text()).toContain(content.titleLead)
    expect(wrapper.text()).toContain(content.titleAccent)
  })

  it('rend chaque paragraphe de présentation', () => {
    const wrapper = mount(AboutSection, { props: { content } })

    for (const paragraph of content.paragraphs) {
      expect(wrapper.text()).toContain(paragraph)
    }
  })

  it('rend une carte par valeur, avec titre et description', () => {
    const wrapper = mount(AboutSection, { props: { content } })

    expect(wrapper.text()).toContain('Rigueur')
    expect(wrapper.text()).toContain('Une exigence de qualité à chaque étape.')
    expect(wrapper.text()).toContain('Curiosité')
    expect(wrapper.findAll('svg').length).toBeGreaterThanOrEqual(content.values.length)
  })
})
