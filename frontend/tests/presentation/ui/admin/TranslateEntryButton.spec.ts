import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import TranslateEntryButton from '../../../../src/presentation/ui/admin/TranslateEntryButton.vue'
import { createAppI18n } from '../../../../src/presentation/i18n'
import { expectNoAccessibilityViolation } from '../../../support/axe'
import { EXPIRED_CALL_PENDING_FR, TRANSLATION_IN_PROGRESS_FR } from '../../../support/adminTranslationTexts'
import { proposeVersionLabel } from '../../../support/adminButtonTexts'

type Props = InstanceType<typeof TranslateEntryButton>['$props']

const HINT_ID = 'admin-test-translation-hint'

function mountButton(props: Partial<Props> & Pick<Props, 'formLocale' | 'isTranslating'>, attrs: Record<string, string> = {}) {
  return mount(TranslateEntryButton, {
    props: { hintId: HINT_ID, ...props },
    attrs,
    global: { plugins: [createAppI18n()] },
  })
}

describe('TranslateEntryButton', () => {
  it('propose la version EN depuis un formulaire en français', () => {
    const wrapper = mountButton({ formLocale: 'fr', isTranslating: false })

    expect(wrapper.get('button').text()).toContain(proposeVersionLabel('en'))
  })

  it('propose la version FR depuis un formulaire en anglais', () => {
    const wrapper = mountButton({ formLocale: 'en', isTranslating: false })

    expect(wrapper.get('button').text()).toContain(proposeVersionLabel('fr'))
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
    expect(wrapper.text()).toContain(TRANSLATION_IN_PROGRESS_FR)
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

  it("pendant l'attente d'un appel annulé, le bouton est désactivé et décrit par le texte de l'attente (#748, R125, décision 2)", () => {
    const wrapper = mountButton({ formLocale: 'fr', isTranslating: false, isExpiredCallPending: true }, { 'aria-describedby': 'aide-page' })

    const button = wrapper.get('button')
    expect(button.attributes('disabled')).toBeDefined()
    expect(button.attributes('aria-describedby')).toBe(`aide-page ${HINT_ID}`)
    expect(wrapper.get(`#${HINT_ID}`).text()).toBe(EXPIRED_CALL_PENDING_FR)
  })

  it("hors attente, aucun texte d'attente et aucune référence vers lui (#748, R125)", () => {
    const wrapper = mountButton({ formLocale: 'fr', isTranslating: false }, { 'aria-describedby': 'aide-page' })

    expect(wrapper.find(`#${HINT_ID}`).exists()).toBe(false)
    expect(wrapper.get('button').attributes('disabled')).toBeUndefined()
    expect(wrapper.get('button').attributes('aria-describedby')).toBe('aide-page')
    expect(mountButton({ formLocale: 'fr', isTranslating: false }).get('button').attributes('aria-describedby')).toBeUndefined()
  })

  it("ne présente aucune violation d'accessibilité pendant l'attente d'un appel annulé (#748, R125)", async () => {
    await expectNoAccessibilityViolation(mountButton({ formLocale: 'fr', isTranslating: false, isExpiredCallPending: true }))
  })
})
