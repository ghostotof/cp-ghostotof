import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import TranslateEntryButton from '../../../../src/presentation/ui/admin/TranslateEntryButton.vue'
import { createAppI18n } from '../../../../src/presentation/i18n'
import { expectNoAccessibilityViolation } from '../../../support/axe'

type Props = InstanceType<typeof TranslateEntryButton>['$props']

function mountButton(props: Props) {
  return mount(TranslateEntryButton, { props, global: { plugins: [createAppI18n()] } })
}

describe('TranslateEntryButton', () => {
  it('propose la version EN depuis un formulaire en français', () => {
    const wrapper = mountButton({ formLocale: 'fr', isTranslating: false })

    expect(wrapper.get('button').text()).toContain('Proposer la version EN')
  })

  it('propose la version FR depuis un formulaire en anglais', () => {
    const wrapper = mountButton({ formLocale: 'en', isTranslating: false })

    expect(wrapper.get('button').text()).toContain('Proposer la version FR')
  })

  it('émet « translate » avec la locale cible, jamais la locale du formulaire', async () => {
    const wrapper = mountButton({ formLocale: 'fr', isTranslating: false })

    await wrapper.get('button').trigger('click')

    expect(wrapper.emitted('translate')).toEqual([['en']])
  })

  it('est un bouton d\'action, pas de soumission : il ne déclenche jamais le formulaire qui l\'entoure', () => {
    const wrapper = mountButton({ formLocale: 'fr', isTranslating: false })

    expect(wrapper.get('button').attributes('type')).toBe('button')
  })

  it('est désactivé et signale aria-busy pendant la traduction', () => {
    const wrapper = mountButton({ formLocale: 'fr', isTranslating: true })

    const button = wrapper.get('button')
    expect(button.attributes('disabled')).toBeDefined()
    expect(button.attributes('aria-busy')).toBe('true')
    expect(wrapper.text()).toContain('Traduction en cours')
  })

  it('reste désactivé quand la page le demande (aucun champ de prose rempli)', () => {
    const wrapper = mountButton({ formLocale: 'fr', isTranslating: false, disabled: true })

    expect(wrapper.get('button').attributes('disabled')).toBeDefined()
    expect(wrapper.get('button').attributes('aria-busy')).toBe('false')
  })

  it('ne présente aucune violation d\'accessibilité, au repos comme en cours', async () => {
    await expectNoAccessibilityViolation(mountButton({ formLocale: 'fr', isTranslating: false }))
    await expectNoAccessibilityViolation(mountButton({ formLocale: 'fr', isTranslating: true }))
  })
})
