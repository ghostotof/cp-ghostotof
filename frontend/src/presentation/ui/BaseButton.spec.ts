import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import BaseButton from './BaseButton.vue'

describe('BaseButton', () => {
  it('rend un lien avec le href fourni et le contenu du slot', () => {
    const wrapper = mount(BaseButton, {
      props: { href: '#contact' },
      slots: { default: 'Me contacter' },
    })

    const link = wrapper.get('a')
    expect(link.attributes('href')).toBe('#contact')
    expect(link.text()).toContain('Me contacter')
  })

  it('applique la classe btn-gradient par défaut (variant primary)', () => {
    const wrapper = mount(BaseButton, { props: { href: '#' } })

    expect(wrapper.classes()).toContain('btn-gradient')
    expect(wrapper.classes()).not.toContain('btn-outline-light')
  })

  it('applique la classe btn-outline-light pour la variant secondary', () => {
    const wrapper = mount(BaseButton, { props: { href: '#', variant: 'secondary' } })

    expect(wrapper.classes()).toContain('btn-outline-light')
    expect(wrapper.classes()).not.toContain('btn-gradient')
  })

  it("n'affiche pas d'icône quand iconKey est absent", () => {
    const wrapper = mount(BaseButton, { props: { href: '#' } })

    expect(wrapper.find('svg').exists()).toBe(false)
  })

  it('affiche une icône quand iconKey correspond à une clé connue', () => {
    const wrapper = mount(BaseButton, { props: { href: '#', iconKey: 'arrow-right' } })

    expect(wrapper.find('svg').exists()).toBe(true)
  })
})
