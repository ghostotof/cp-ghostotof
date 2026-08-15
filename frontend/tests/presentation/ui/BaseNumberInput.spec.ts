import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import BaseNumberInput from '../../../src/presentation/ui/BaseNumberInput.vue'

describe('BaseNumberInput', () => {
  it('rend un label associé au champ (for/id) et la valeur fournie', () => {
    const wrapper = mount(BaseNumberInput, {
      props: { modelValue: 9.5, label: 'Années', id: 'tech-years' },
    })

    const label = wrapper.get('label')
    expect(label.text()).toBe('Années')
    expect(label.attributes('for')).toBe('tech-years')

    const input = wrapper.get('input')
    expect(input.attributes('id')).toBe('tech-years')
    expect(input.attributes('type')).toBe('number')
    expect(input.element.value).toBe('9.5')
  })

  it('émet update:modelValue avec une valeur numérique à la saisie', async () => {
    const wrapper = mount(BaseNumberInput, {
      props: { modelValue: 0, label: 'Années', id: 'tech-years' },
    })

    await wrapper.get('input').setValue('4.5')

    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([4.5])
  })

  it('émet 0 quand le champ est vidé', async () => {
    const wrapper = mount(BaseNumberInput, {
      props: { modelValue: 4, label: 'Années', id: 'tech-years' },
    })

    await wrapper.get('input').setValue('')

    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([0])
  })
})
