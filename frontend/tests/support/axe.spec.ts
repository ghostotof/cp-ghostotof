import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent } from 'vue'
import { expectNoAccessibilityViolation } from './axe'

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
