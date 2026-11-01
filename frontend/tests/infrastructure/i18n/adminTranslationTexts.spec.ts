import { describe, expect, it } from 'vitest'
import en from '../../../src/infrastructure/i18n/locales/en.json'
import { EXPIRED_CALL_PENDING_FR, PROSE_READONLY_FR } from '../../support/adminTranslationTexts'
import { ENTRY_LOCK_PROPOSAL_FR } from '../../support/entryFormLockTexts'

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

  it("l'aide de la prose en lecture seule ne promet pas l'ouverture de la proposition : la réponse peut être périmée ou refusée", () => {
    // Une réponse périmée n'ouvre rien, et les réglages ne l'ouvrent pas toujours dans la langue cible : la réponse « peut » ouvrir.
    expect(PROSE_READONLY_FR).not.toContain('Si elle aboutit')
    expect(PROSE_READONLY_FR).not.toContain("la proposition s'ouvre")
    expect(PROSE_READONLY_FR).toContain('qui peut ouvrir une proposition dans la langue cible')

    expect(en.admin.translation.proseReadonly).not.toContain('If it succeeds')
    expect(en.admin.translation.proseReadonly).not.toContain('the proposal opens')
    expect(en.admin.translation.proseReadonly).toContain('which may open a proposal in the target language')
  })

  it("l'aide du verrou de proposition, en anglais, dit que « Cancel » abandonne et vide le formulaire, sans « discard »", () => {
    // « discard » est réservé aux réglages « À propos » (R154) ; le français, lui, reste « l'abandonne et vide le formulaire ».
    expect(en.admin.entryLock.proposal).not.toContain('discards')
    expect(en.admin.entryLock.proposal).toContain('“Cancel” drops it and clears the form.')
    expect(ENTRY_LOCK_PROPOSAL_FR).toContain("« Annuler » l'abandonne et vide le formulaire.")
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
