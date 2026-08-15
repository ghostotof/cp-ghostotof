import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import BaseSelect from '../../../src/presentation/ui/BaseSelect.vue'

const OPTIONS = [
  { value: 'fr', label: 'Français' },
  { value: 'en', label: 'Anglais' },
]

describe('BaseSelect', () => {
  it('rend un label associé au champ (for/id), les options et la valeur sélectionnée', () => {
    const wrapper = mount(BaseSelect, {
      props: { modelValue: 'en', label: 'Langue', id: 'locale', options: OPTIONS },
    })

    const label = wrapper.get('label')
    expect(label.text()).toBe('Langue')
    expect(label.attributes('for')).toBe('locale')

    const select = wrapper.get('select')
    expect(select.attributes('id')).toBe('locale')
    expect(select.element.value).toBe('en')

    const optionTexts = wrapper.findAll('option').map((option) => option.text())
    expect(optionTexts).toEqual(['Français', 'Anglais'])
  })

  it('émet update:modelValue à la sélection', async () => {
    const wrapper = mount(BaseSelect, {
      props: { modelValue: 'fr', label: 'Langue', id: 'locale', options: OPTIONS },
    })

    await wrapper.get('select').setValue('en')

    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['en'])
  })
})
