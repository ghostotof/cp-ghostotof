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

  /**
   * Issue #164 : un attribut passé par le parent (`aria-describedby` vers un
   * texte d'aide) doit atterrir sur le champ, pas sur le `<div>` racine —
   * sinon l'association champ → aide n'existe pas pour un lecteur d'écran.
   */
  it("pose les attributs du parent sur le champ, pas sur le conteneur", () => {
    const wrapper = mount(BaseTextarea, {
      props: { modelValue: '', label: 'Corps', id: 'body' },
      attrs: { 'aria-describedby': 'help-text' },
    })

    expect(wrapper.get('textarea').attributes('aria-describedby')).toBe('help-text')
    expect(wrapper.element.getAttribute('aria-describedby')).toBeNull()
  })
})
