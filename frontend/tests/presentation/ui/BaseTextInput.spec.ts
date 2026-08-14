import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import BaseTextInput from '../../../src/presentation/ui/BaseTextInput.vue'

describe('BaseTextInput', () => {
  it('rend un label associé au champ (for/id) et la valeur fournie', () => {
    const wrapper = mount(BaseTextInput, {
      props: { modelValue: 'Symfony', label: 'Nom', id: 'tech-name' },
    })

    const label = wrapper.get('label')
    expect(label.text()).toBe('Nom')
    expect(label.attributes('for')).toBe('tech-name')

    const input = wrapper.get('input')
    expect(input.attributes('id')).toBe('tech-name')
    expect(input.element.value).toBe('Symfony')
  })

  it('émet update:modelValue à la saisie', async () => {
    const wrapper = mount(BaseTextInput, {
      props: { modelValue: '', label: 'Nom', id: 'tech-name' },
    })

    await wrapper.get('input').setValue('PHP')

    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['PHP'])
  })

  it("n'affiche pas de message d'erreur par défaut", () => {
    const wrapper = mount(BaseTextInput, {
      props: { modelValue: '', label: 'Nom', id: 'tech-name' },
    })

    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
  })

  it("affiche le message d'erreur fourni avec role=alert", () => {
    const wrapper = mount(BaseTextInput, {
      props: { modelValue: '', label: 'Nom', id: 'tech-name', error: 'Ce champ est requis.' },
    })

    expect(wrapper.get('[role="alert"]').text()).toBe('Ce champ est requis.')
  })
})
