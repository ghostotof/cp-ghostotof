import { describe, expect, it } from 'vitest'
import en from '../../src/infrastructure/i18n/locales/en.json'
import { DISCARD_PENDING_FR, PROSE_READONLY_FR } from './adminTranslationTexts'

/**
 * La justesse des deux textes de la traduction assistée (#748, R142) : c'est ici, et nulle part ailleurs, que leur
 * rédaction se juge ; les specs de page, elles, comparent l'identité du texte que désigne un `aria-describedby`.
 */
describe('textes de la traduction assistée (#748, R142)', () => {
  it("l'aide de la prose en lecture seule dit que la réponse remplace la saisie, sans promettre qu'elle est gardée", () => {
    expect(PROSE_READONLY_FR).not.toContain("n'est perdu")
    expect(PROSE_READONLY_FR).toContain('remplace')
    expect(PROSE_READONLY_FR).toContain("l'entrée enregistrée ne change pas")

    expect(en.admin.translation.proseReadonly).not.toContain('is lost')
    expect(en.admin.translation.proseReadonly).toContain('replaces')
    expect(en.admin.translation.proseReadonly).toContain('the saved entry does not change')
  })

  it("l'attente du bouton parle de la traduction précédente, pas d'une traduction annulée par un seul geste", () => {
    expect(DISCARD_PENDING_FR.startsWith('La traduction précédente')).toBe(true)
    expect(DISCARD_PENDING_FR).not.toContain('annulée')

    expect(en.admin.translation.discardPending.startsWith('The previous translation')).toBe(true)
    expect(en.admin.translation.discardPending).not.toContain('cancelled')
  })

  it('chaque texte existe en anglais et diffère du français', () => {
    expect(en.admin.translation.proseReadonly).not.toBe(PROSE_READONLY_FR)
    expect(en.admin.translation.discardPending).not.toBe(DISCARD_PENDING_FR)
  })
})
