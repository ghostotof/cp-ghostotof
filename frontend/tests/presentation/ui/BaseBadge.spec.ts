import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import BaseBadge from '../../../src/presentation/ui/BaseBadge.vue'

describe('BaseBadge', () => {
  it('rend le contenu du slot', () => {
    const wrapper = mount(BaseBadge, { slots: { default: 'Tests automatisés' } })

    expect(wrapper.text()).toContain('Tests automatisés')
    expect(wrapper.classes()).toContain('badge-soft')
  })

  it("n'affiche pas d'icône sans iconKey", () => {
    const wrapper = mount(BaseBadge, { slots: { default: 'Sans icône' } })

    expect(wrapper.find('svg').exists()).toBe(false)
  })

  it('affiche une icône pour une iconKey connue', () => {
    const wrapper = mount(BaseBadge, {
      props: { iconKey: 'check' },
      slots: { default: 'Avec icône' },
    })

    expect(wrapper.find('svg').exists()).toBe(true)
  })
})
