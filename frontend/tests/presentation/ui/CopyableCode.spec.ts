import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import CopyableCode from '../../../src/presentation/ui/CopyableCode.vue'
import { createAppI18n } from '../../../src/presentation/i18n'
import { expectNoAccessibilityViolation } from '../../support/axe'
import { removeClipboard, stubClipboard, type WriteText } from '../../support/clipboard'

const CODE = 'claude mcp add --transport http cp-ghostotof https://site.example.test/api/mcp'

function mountBlock(locale: 'fr' | 'en' = 'fr', label = 'la commande Claude Code') {
  const i18n = createAppI18n()
  i18n.global.locale.value = locale

  return mount(CopyableCode, {
    props: { code: CODE, label },
    global: { plugins: [i18n] },
  })
}

describe('CopyableCode', () => {
  let writeText: WriteText

  beforeEach(() => {
    writeText = stubClipboard()
  })

  afterEach(() => {
    removeClipboard()
  })

  it('affiche le code à copier tel quel', () => {
    expect(mountBlock().find('pre code').text()).toBe(CODE)
  })

  /**
   * Un vrai <button type="button"> : focusable au clavier, activé par Entrée
   * et Espace sans gestionnaire maison — ce qu'un <div @click> ne fait pas.
   */
  it('copie par un bouton natif, donc atteignable et activable au clavier', () => {
    const button = mountBlock().find('button')

    expect(button.element.tagName).toBe('BUTTON')
    expect(button.attributes('type')).toBe('button')
  })

  /**
   * Plusieurs blocs sur une page : « Copier » seul ne dirait pas lequel. Le mot
   * visible reste en tête du nom accessible (WCAG 2.5.3, l'étiquette visible
   * est dans le nom), le complément n'est lu que par les lecteurs d'écran.
   */
  it('nomme ce que le bouton copie, le mot visible en tête du nom accessible', () => {
    const button = mountBlock().find('button')

    expect(button.text()).toBe('Copier la commande Claude Code')
    expect(button.find('.visually-hidden').text()).toBe('la commande Claude Code')
  })

  it('copie exactement le code affiché dans le presse-papiers', async () => {
    const wrapper = mountBlock()

    await wrapper.find('button').trigger('click')
    await flushPromises()

    expect(writeText).toHaveBeenCalledWith(CODE)
  })

  /**
   * La région doit exister, vide, avant la copie : un lecteur d'écran n'annonce
   * pas une région live insérée en même temps que son texte.
   */
  it('prépare une région role="status" vide avant toute copie', () => {
    const status = mountBlock().find('[role="status"]')

    expect(status.exists()).toBe(true)
    expect(status.text()).toBe('')
  })

  it('annonce la copie réussie aux lecteurs d’écran', async () => {
    const wrapper = mountBlock()

    await wrapper.find('button').trigger('click')
    await flushPromises()

    expect(wrapper.find('[role="status"]').text()).toBe('Copié dans le presse-papiers.')
  })

  it('annonce la copie en anglais sur la version anglaise', async () => {
    const wrapper = mountBlock('en', 'the Claude Code command')

    await wrapper.find('button').trigger('click')
    await flushPromises()

    expect(wrapper.find('button').text()).toBe('Copy the Claude Code command')
    expect(wrapper.find('[role="status"]').text()).toBe('Copied to the clipboard.')
  })

  /**
   * Un texte identique n'est pas réannoncé : la région est vidée avant chaque
   * copie, pour qu'une deuxième copie soit confirmée comme la première.
   */
  it('vide la région avant une nouvelle copie, pour la réannoncer', async () => {
    const wrapper = mountBlock()
    await wrapper.find('button').trigger('click')
    await flushPromises()

    void wrapper.find('button').trigger('click')
    await nextTick()
    expect(wrapper.find('[role="status"]').text()).toBe('')

    await flushPromises()
    expect(wrapper.find('[role="status"]').text()).toBe('Copié dans le presse-papiers.')
  })

  it('annonce l’échec et invite à copier à la main quand le navigateur refuse', async () => {
    stubClipboard(vi.fn(async () => Promise.reject(new DOMException('refusé', 'NotAllowedError'))))
    const wrapper = mountBlock()

    await wrapper.find('button').trigger('click')
    await flushPromises()

    expect(wrapper.find('[role="status"]').text()).toBe(
      'La copie a échoué : sélectionnez le texte pour le copier à la main.',
    )
  })

  /**
   * `navigator.clipboard` n'existe qu'en contexte sécurisé (HTTPS, localhost) :
   * ailleurs, l'appel lèverait un TypeError que personne ne verrait.
   */
  it('annonce l’échec quand le presse-papiers est indisponible', async () => {
    removeClipboard()
    const wrapper = mountBlock()

    await wrapper.find('button').trigger('click')
    await flushPromises()

    expect(wrapper.find('[role="status"]').text()).toBe(
      'La copie a échoué : sélectionnez le texte pour le copier à la main.',
    )
  })

  it('ne présente aucune violation d’accessibilité détectable', async () => {
    await expectNoAccessibilityViolation(mountBlock())
  })
})
