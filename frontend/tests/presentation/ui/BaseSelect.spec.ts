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

  /**
   * Issue #164 : un attribut passé par le parent (`aria-describedby` vers un
   * texte d'aide) doit atterrir sur le champ, pas sur le `<div>` racine —
   * sinon l'association champ → aide n'existe pas pour un lecteur d'écran.
   */
  it("pose les attributs du parent sur le champ, pas sur le conteneur", () => {
    const wrapper = mount(BaseSelect, {
      props: { modelValue: 'fr', label: 'Langue', id: 'locale', options: [{ value: 'fr', label: 'FR' }] },
      attrs: { 'aria-describedby': 'help-text' },
    })

    expect(wrapper.get('select').attributes('aria-describedby')).toBe('help-text')
    expect(wrapper.element.getAttribute('aria-describedby')).toBeNull()
  })
})
