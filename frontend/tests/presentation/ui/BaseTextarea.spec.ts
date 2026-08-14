import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import BaseTextarea from '../../../src/presentation/ui/BaseTextarea.vue'

describe('BaseTextarea', () => {
  it('rend un label associé au champ (for/id) et la valeur fournie', () => {
    const wrapper = mount(BaseTextarea, {
      props: { modelValue: 'Une description.', label: 'Description', id: 'card-description' },
    })

    const label = wrapper.get('label')
    expect(label.text()).toBe('Description')
    expect(label.attributes('for')).toBe('card-description')

    const textarea = wrapper.get('textarea')
    expect(textarea.attributes('id')).toBe('card-description')
    expect(textarea.element.value).toBe('Une description.')
  })

  it('émet update:modelValue à la saisie', async () => {
    const wrapper = mount(BaseTextarea, {
      props: { modelValue: '', label: 'Description', id: 'card-description' },
    })

    await wrapper.get('textarea').setValue('Nouveau texte')

    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['Nouveau texte'])
  })

  it("affiche le message d'erreur fourni avec role=alert", () => {
    const wrapper = mount(BaseTextarea, {
      props: { modelValue: '', label: 'Description', id: 'card-description', error: 'Ce champ est requis.' },
    })

    expect(wrapper.get('[role="alert"]').text()).toBe('Ce champ est requis.')
  })
})
