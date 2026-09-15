import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import BaseDateInput from '../../../src/presentation/ui/BaseDateInput.vue'

describe('BaseDateInput', () => {
  it('rend un label associé au champ (for/id), un type date et la valeur ISO fournie', () => {
    const wrapper = mount(BaseDateInput, {
      props: { modelValue: '2026-09-15', label: 'Date', id: 'occurred-at' },
    })

    const label = wrapper.get('label')
    expect(label.text()).toBe('Date')
    expect(label.attributes('for')).toBe('occurred-at')

    const input = wrapper.get('input')
    expect(input.attributes('id')).toBe('occurred-at')
    expect(input.attributes('type')).toBe('date')
    expect(input.element.value).toBe('2026-09-15')
  })

  it('émet update:modelValue à la saisie', async () => {
    const wrapper = mount(BaseDateInput, {
      props: { modelValue: '', label: 'Date', id: 'occurred-at' },
    })

    await wrapper.get('input').setValue('2026-01-31')

    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['2026-01-31'])
  })

  /**
   * Issue #164 : un attribut passé par le parent (`aria-describedby` vers un
   * texte d'aide) doit atterrir sur le champ, pas sur le `<div>` racine —
   * sinon l'association champ → aide n'existe pas pour un lecteur d'écran.
   */
  it("pose les attributs du parent sur le champ, pas sur le conteneur", () => {
    const wrapper = mount(BaseDateInput, {
      props: { modelValue: '', label: 'Date', id: 'occurred-at' },
      attrs: { 'aria-describedby': 'help-text' },
    })

    expect(wrapper.get('input').attributes('aria-describedby')).toBe('help-text')
    expect(wrapper.element.getAttribute('aria-describedby')).toBeNull()
  })
})
