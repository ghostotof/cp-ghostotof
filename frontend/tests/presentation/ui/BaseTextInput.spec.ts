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

  /**
   * Issue #164 : un attribut passé par le parent (`aria-describedby` vers un
   * texte d'aide) doit atterrir sur le champ, pas sur le `<div>` racine —
   * sinon l'association champ → aide n'existe pas pour un lecteur d'écran.
   */
  it("pose les attributs du parent sur le champ, pas sur le conteneur", () => {
    const wrapper = mount(BaseTextInput, {
      props: { modelValue: '', label: 'Nom', id: 'tech-name' },
      attrs: { 'aria-describedby': 'help-text' },
    })

    expect(wrapper.get('input').attributes('aria-describedby')).toBe('help-text')
    expect(wrapper.element.getAttribute('aria-describedby')).toBeNull()
  })
})
