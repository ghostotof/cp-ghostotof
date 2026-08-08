import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import BaseCard from './BaseCard.vue'

describe('BaseCard', () => {
  it('rend le titre', () => {
    const wrapper = mount(BaseCard, { props: { title: 'SOLID' } })

    expect(wrapper.get('h3').text()).toBe('SOLID')
  })

  it('rend la description quand elle est fournie', () => {
    const wrapper = mount(BaseCard, {
      props: { title: 'SOLID', description: 'Des bases solides et maintenables.' },
    })

    expect(wrapper.text()).toContain('Des bases solides et maintenables.')
  })

  it("n'affiche aucun paragraphe quand la description est absente", () => {
    const wrapper = mount(BaseCard, { props: { title: 'SOLID' } })

    expect(wrapper.find('p').exists()).toBe(false)
  })

  it('applique la classe de mise en avant quand highlighted est vrai', () => {
    const wrapper = mount(BaseCard, { props: { title: 'Symfony', highlighted: true } })

    expect(wrapper.classes()).toContain('card-portfolio--highlighted')
  })

  it("n'applique pas la classe de mise en avant par défaut", () => {
    const wrapper = mount(BaseCard, { props: { title: 'Docker' } })

    expect(wrapper.classes()).not.toContain('card-portfolio--highlighted')
  })

  it('affiche une icône uniquement quand iconKey correspond à une clé connue', () => {
    const withIcon = mount(BaseCard, { props: { title: 'Symfony', iconKey: 'symfony' } })
    const withoutIcon = mount(BaseCard, { props: { title: 'Sans icône' } })

    expect(withIcon.find('svg').exists()).toBe(true)
    expect(withoutIcon.find('svg').exists()).toBe(false)
  })
})
