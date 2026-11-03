import axe from 'axe-core'
import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent } from 'vue'
import { accessibleNameOf, expectNoAccessibilityViolation } from './axe'
import { proposeVersionLabel } from './adminButtonTexts'

/**
 * L'adaptateur se teste lui-même, et ce n'est pas de la coquetterie : un audit
 * d'accessibilité qui ne détecte rien passe au vert exactement comme un audit
 * qui ne trouve rien à redire. Sans ces tests, on ne saurait pas distinguer
 * « la page est propre » de « l'outil ne regarde pas ».
 */
describe('expectNoAccessibilityViolation', () => {
  it('accepte un fragment conforme', async () => {
    const Propre = defineComponent({
      template: `
        <main>
          <h1>Titre</h1>
          <h2>Sous-titre</h2>
          <p>Du texte.</p>
        </main>`,
    })

    await expect(expectNoAccessibilityViolation(mount(Propre))).resolves.toBeUndefined()
  })

  it('détecte un saut de niveau de titre', async () => {
    const Saut = defineComponent({
      template: '<main><h1>Titre</h1><h4>Trop bas</h4></main>',
    })

    await expect(expectNoAccessibilityViolation(mount(Saut))).rejects.toThrow(/heading-order/)
  })

  it('détecte une image sans alternative textuelle', async () => {
    const SansAlt = defineComponent({
      template: '<main><img src="x.png"></main>',
    })

    await expect(expectNoAccessibilityViolation(mount(SansAlt))).rejects.toThrow(/image-alt/)
  })

  it('détecte un champ sans libellé', async () => {
    const SansLabel = defineComponent({
      template: '<main><input type="text"></main>',
    })

    await expect(expectNoAccessibilityViolation(mount(SansLabel))).rejects.toThrow(/label/)
  })

  /**
   * Le message doit désigner l'élément fautif. Un rapport qui ne donne que
   * l'identifiant de la règle oblige à retrouver le coupable à la main, et
   * décourage l'usage de l'outil.
   */
  it('nomme l\'élément en cause dans son message', async () => {
    const SansAlt = defineComponent({
      template: '<main><img src="coupable.png"></main>',
    })

    await expect(expectNoAccessibilityViolation(mount(SansAlt))).rejects.toThrow(/coupable\.png/)
  })
})

/**
 * `accessibleNameOf` sert à retrouver un bouton par son nom, celui que lit un lecteur d'écran, plutôt que par le texte du DOM :
 * les deux diffèrent dès qu'un bouton porte une étiquette ARIA ou un contenu masqué.
 */
describe('accessibleNameOf', () => {
  it("rend le contenu d'un bouton sans ce qu'il masque aux lecteurs d'écran", () => {
    const label = proposeVersionLabel('en')
    const Bouton = defineComponent({
      template: `<button type="button"><span aria-hidden="true">chargement</span><span>${label}</span></button>`,
    })

    expect(accessibleNameOf(mount(Bouton).element)).toBe(label)
  })

  it("préfère l'étiquette ARIA au contenu, comme le fait un lecteur d'écran", () => {
    const Etiquette = defineComponent({
      template: '<button type="button" aria-label="Fermer"><span>×</span></button>',
    })

    expect(accessibleNameOf(mount(Etiquette).element)).toBe('Fermer')
  })

  it("laisse l'élément et le document tels qu'ils étaient : l'élément reste détaché", () => {
    const Bouton = defineComponent({ template: '<button type="button">Texte</button>' })
    const wrapper = mount(Bouton)
    const before = document.body.innerHTML

    accessibleNameOf(wrapper.element)

    expect(document.body.contains(wrapper.element)).toBe(false)
    expect(document.body.innerHTML).toBe(before)
  })

  // CR16-9 : la copie est rattachée au document avant `axe.setup` ; si `setup` lève, elle ne doit pas y rester, sinon elle
  // s'ajoute au DOM de tous les cas suivants du fichier de spec.
  it("ne laisse aucune copie dans le document, même si axe.setup lève (#748, R200)", () => {
    const Bouton = defineComponent({ template: '<button type="button">Texte</button>' })
    const wrapper = mount(Bouton)
    const before = document.body.innerHTML
    const setup = vi.spyOn(axe, 'setup').mockImplementation(() => {
      throw new Error('setup refusé')
    })

    try {
      expect(() => accessibleNameOf(wrapper.element)).toThrow('setup refusé')
      expect(document.body.innerHTML).toBe(before)
    } finally {
      setup.mockRestore()
    }
  })
})
