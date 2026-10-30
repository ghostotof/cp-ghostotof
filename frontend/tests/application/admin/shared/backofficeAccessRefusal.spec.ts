import { describe, expect, it } from 'vitest'
import {
  backofficeAccessRefused,
  clearBackofficeAccessRefusal,
  earlierSaveRefused,
  markBackofficeAccessRefused,
  refusalScope,
  settleBackofficeAccessRefusalsOnMount,
} from '../../../../src/application/admin/shared/backofficeAccessRefusal'
import type { RefusalScope } from '../../../../src/domain/admin/shared/BackofficeSessionReaction'

/** Les comptes des écritures de ces tests : le module retient le nom, jamais plus (R76). */
const ACCOUNT_A = 'compte-a'
const ACCOUNT_B = 'compte-b'

/** Ce qu'une lecture envoyée maintenant relève à l'envoi (R54). */
function readSentNow(): RefusalScope {
  return { kind: 'read', scope: refusalScope() }
}

/**
 * Le compteur des refus réels et son périmètre (#748, R28, CR3-8) : une navigation (un effacement) avance le
 * périmètre, et un refus relevé sous un périmètre révolu ne compte pas. Une écriture (`{ kind: 'write' }`) n'est jamais périmée
 * (R38). Aucun `afterEach` ici : `tests/support/setup.ts` remet l'état à zéro avant chaque test, et les deux
 * derniers tests le prouvent.
 */
describe('backofficeAccessRefusal', () => {
  it('un refus du périmètre courant compte et le dit', () => {
    expect(markBackofficeAccessRefused(readSentNow(), ACCOUNT_A)).toBe(true)
    expect(markBackofficeAccessRefused(readSentNow(), ACCOUNT_A)).toBe(true)

    expect(backofficeAccessRefused.value).toBe(2)
  })

  it('un effacement avance le périmètre : le refus relevé avant ne compte plus, rien ne change', () => {
    const sentBefore = refusalScope()
    clearBackofficeAccessRefusal()

    expect(refusalScope()).not.toBe(sentBefore)
    expect(markBackofficeAccessRefused({ kind: 'read', scope: sentBefore }, ACCOUNT_A)).toBe(false)
    expect(backofficeAccessRefused.value).toBe(0)
  })

  it('un effacement remet le compteur à zéro', () => {
    markBackofficeAccessRefused(readSentNow(), ACCOUNT_A)

    clearBackofficeAccessRefusal()

    expect(backofficeAccessRefused.value).toBe(0)
  })

  it("au montage, une écriture comptée garde le compteur et se dit « enregistrement précédent » ; le périmètre ne bouge pas (R64)", () => {
    const sentFromThisPage = refusalScope()
    markBackofficeAccessRefused({ kind: 'write' }, ACCOUNT_A)

    settleBackofficeAccessRefusalsOnMount(ACCOUNT_A)

    expect(backofficeAccessRefused.value).toBe(1)
    expect(earlierSaveRefused.value).toBe(true)
    expect(refusalScope()).toBe(sentFromThisPage)
    expect(markBackofficeAccessRefused({ kind: 'read', scope: sentFromThisPage }, ACCOUNT_A)).toBe(true)
  })

  it("au montage, un refus de lecture seul est oublié : compteur à zéro, rien à dire, périmètre inchangé (R64)", () => {
    const sentFromThisPage = refusalScope()
    markBackofficeAccessRefused({ kind: 'read', scope: sentFromThisPage }, ACCOUNT_A)

    settleBackofficeAccessRefusalsOnMount(ACCOUNT_A)

    expect(backofficeAccessRefused.value).toBe(0)
    expect(earlierSaveRefused.value).toBe(false)
    expect(refusalScope()).toBe(sentFromThisPage)
  })

  it("au montage, l'écriture comptée du compte courant est dite", () => {
    markBackofficeAccessRefused({ kind: 'write' }, ACCOUNT_A)

    settleBackofficeAccessRefusalsOnMount(ACCOUNT_A)

    expect(earlierSaveRefused.value).toBe(true)
    expect(backofficeAccessRefused.value).toBe(1)
  })

  it("au montage, l'écriture comptée d'un autre compte remet tout à zéro", () => {
    const sentFromThisPage = refusalScope()
    markBackofficeAccessRefused({ kind: 'write' }, ACCOUNT_A)

    settleBackofficeAccessRefusalsOnMount(ACCOUNT_B)

    expect(earlierSaveRefused.value).toBe(false)
    expect(backofficeAccessRefused.value).toBe(0)
    expect(refusalScope()).toBe(sentFromThisPage)
    // L'écriture comptée est oubliée aussi : un montage suivant, même sous le compte A, n'a plus rien à dire.
    settleBackofficeAccessRefusalsOnMount(ACCOUNT_A)
    expect(earlierSaveRefused.value).toBe(false)
  })

  it("au montage, une écriture comptée sous aucun compte n'est dite qu'à « aucun compte » (null contre null, R76)", () => {
    markBackofficeAccessRefused({ kind: 'write' }, null)

    settleBackofficeAccessRefusalsOnMount(ACCOUNT_A)
    expect(earlierSaveRefused.value).toBe(false)

    markBackofficeAccessRefused({ kind: 'write' }, null)
    settleBackofficeAccessRefusalsOnMount(null)
    expect(earlierSaveRefused.value).toBe(true)
  })

  it("après le montage, une lecture refusée garde l'avis et fait avancer le compteur", () => {
    markBackofficeAccessRefused({ kind: 'write' }, ACCOUNT_A)
    settleBackofficeAccessRefusalsOnMount(ACCOUNT_A)
    expect(earlierSaveRefused.value).toBe(true)
    expect(backofficeAccessRefused.value).toBe(1)

    markBackofficeAccessRefused(readSentNow(), ACCOUNT_A)

    expect(earlierSaveRefused.value).toBe(true)
    expect(backofficeAccessRefused.value).toBe(2)
  })

  it("après le montage, la première lecture refusée garde l'avis, la seconde ramène le texte courant (R95, décision 4)", () => {
    markBackofficeAccessRefused({ kind: 'write' }, ACCOUNT_A)
    settleBackofficeAccessRefusalsOnMount(ACCOUNT_A)

    // La première : le chargement de la page d'arrivée, parti avant le montage.
    markBackofficeAccessRefused(readSentNow(), ACCOUNT_A)
    expect(earlierSaveRefused.value).toBe(true)

    // La seconde : une relecture ultérieure (rôle perdu), ce n'est plus le chargement ; le compteur avance quand même.
    markBackofficeAccessRefused(readSentNow(), ACCOUNT_A)
    expect(earlierSaveRefused.value).toBe(false)
    expect(backofficeAccessRefused.value).toBe(3)

    // Et l'avis ne revient pas avec une troisième lecture.
    markBackofficeAccessRefused(readSentNow(), ACCOUNT_A)
    expect(earlierSaveRefused.value).toBe(false)
  })

  it("une écriture comptée sous aucun compte se distingue de l'absence d'écriture (St8-10)", () => {
    // Aucune écriture comptée, aucun compte au montage : rien à dire, même si « aucun » égale « aucun ».
    markBackofficeAccessRefused(readSentNow(), null)
    settleBackofficeAccessRefusalsOnMount(null)
    expect(earlierSaveRefused.value).toBe(false)
    expect(backofficeAccessRefused.value).toBe(0)

    // Une écriture comptée sous aucun compte, aucun compte au montage : l'avis est dit.
    markBackofficeAccessRefused({ kind: 'write' }, null)
    settleBackofficeAccessRefusalsOnMount(null)
    expect(earlierSaveRefused.value).toBe(true)
  })

  it("après le montage, une écriture refusée ramène le texte courant", () => {
    markBackofficeAccessRefused({ kind: 'write' }, ACCOUNT_A)
    settleBackofficeAccessRefusalsOnMount(ACCOUNT_A)
    expect(earlierSaveRefused.value).toBe(true)

    markBackofficeAccessRefused({ kind: 'write' }, ACCOUNT_A)

    expect(earlierSaveRefused.value).toBe(false)
    expect(backofficeAccessRefused.value).toBe(2)
  })

  it("un effacement remet l'avis à faux et oublie l'écriture comptée (R64)", () => {
    markBackofficeAccessRefused({ kind: 'write' }, ACCOUNT_A)
    settleBackofficeAccessRefusalsOnMount(ACCOUNT_A)
    expect(earlierSaveRefused.value).toBe(true)

    // Un effacement oublie aussi l'écriture comptée : le montage suivant n'a rien à dire.
    clearBackofficeAccessRefusal()
    settleBackofficeAccessRefusalsOnMount(ACCOUNT_A)
    expect(earlierSaveRefused.value).toBe(false)
    expect(backofficeAccessRefused.value).toBe(0)
  })

  it("un refus d'écriture (`{ kind: 'write' }`) compte toujours, même après une navigation", () => {
    clearBackofficeAccessRefusal()

    expect(markBackofficeAccessRefused({ kind: 'write' }, ACCOUNT_A)).toBe(true)
    expect(backofficeAccessRefused.value).toBe(1)
  })

  // Deux tests successifs : le premier laisse un refus compté et un périmètre avancé, le second les doit à zéro.
  it('remise à zéro (1/2) : un refus compté et un périmètre avancé', () => {
    markBackofficeAccessRefused(readSentNow(), ACCOUNT_A)
    clearBackofficeAccessRefusal()
    markBackofficeAccessRefused(readSentNow(), ACCOUNT_A)
    markBackofficeAccessRefused({ kind: 'write' }, ACCOUNT_A)
    settleBackofficeAccessRefusalsOnMount(ACCOUNT_A)

    expect(backofficeAccessRefused.value).toBe(2)
    expect(earlierSaveRefused.value).toBe(true)
    expect(refusalScope()).toBeGreaterThan(0)
  })

  it("remise à zéro (2/2) : le test précédent n'a rien laissé", () => {
    // Ni l'écriture comptée (drapeau du module) ni le texte « enregistrement précédent » ne survivent à la remise à zéro.
    settleBackofficeAccessRefusalsOnMount(ACCOUNT_A)

    expect(backofficeAccessRefused.value).toBe(0)
    expect(earlierSaveRefused.value).toBe(false)
    expect(refusalScope()).toBe(0)
  })
})
