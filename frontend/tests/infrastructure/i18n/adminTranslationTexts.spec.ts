import { describe, expect, it } from 'vitest'
import en from '../../../src/infrastructure/i18n/locales/en.json'
import { EXPIRED_CALL_PENDING_FR, PROSE_READONLY_FR } from '../../support/adminTranslationTexts'

/**
 * La justesse des deux textes de la traduction assistée (#748, R142) : c'est ici, et nulle part ailleurs, que leur
 * rédaction se juge ; les specs de page, elles, comparent l'identité du texte que désigne un `aria-describedby`.
 */
describe('textes de la traduction assistée (#748, R142)', () => {
  it("l'aide de la prose en lecture seule ne promet ni la perte de la saisie ni son remplacement par la réponse", () => {
    // La réponse peut échouer, arriver périmée, ou (réglages) s'ouvrir dans l'autre langue : « remplace » serait faux.
    expect(PROSE_READONLY_FR).not.toContain("n'est perdu")
    expect(PROSE_READONLY_FR).not.toContain('les remplace par la proposition')

    expect(en.admin.translation.proseReadonly).not.toContain('is lost')
    expect(en.admin.translation.proseReadonly).not.toContain('replaces them with the proposal')
  })

  it("l'attente du bouton parle de la traduction précédente, pas d'une traduction annulée par un seul geste", () => {
    expect(EXPIRED_CALL_PENDING_FR.startsWith('La traduction précédente')).toBe(true)
    expect(EXPIRED_CALL_PENDING_FR).not.toContain('annulée')

    expect(en.admin.translation.expiredCallPending.startsWith('The previous translation')).toBe(true)
    expect(en.admin.translation.expiredCallPending).not.toContain('cancelled')
  })

  it('chaque texte existe en français et en anglais, non vide, et diffère d\'une langue à l\'autre', () => {
    const { proseReadonly, expiredCallPending } = en.admin.translation
    for (const text of [PROSE_READONLY_FR, EXPIRED_CALL_PENDING_FR, proseReadonly, expiredCallPending]) {
      expect(text.trim()).not.toBe('')
    }
    expect(proseReadonly).not.toBe(PROSE_READONLY_FR)
    expect(expiredCallPending).not.toBe(EXPIRED_CALL_PENDING_FR)
  })
})
