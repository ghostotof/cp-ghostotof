import { describe, expect, it } from 'vitest'
import {
  backofficeAccessRefused,
  clearBackofficeAccessRefusal,
  earlierWriteRefused,
  markBackofficeAccessRefused,
  refusalScope,
  settleBackofficeAccessRefusalsOnMount,
} from '../../../../src/application/admin/shared/backofficeAccessRefusal'
import type { RefusalScope } from '../../../../src/domain/admin/shared/BackofficeSessionReaction'

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
    expect(markBackofficeAccessRefused(readSentNow())).toBe(true)
    expect(markBackofficeAccessRefused(readSentNow())).toBe(true)

    expect(backofficeAccessRefused.value).toBe(2)
  })

  it('un effacement avance le périmètre : le refus relevé avant ne compte plus, rien ne change', () => {
    const sentBefore = refusalScope()
    clearBackofficeAccessRefusal()

    expect(refusalScope()).not.toBe(sentBefore)
    expect(markBackofficeAccessRefused({ kind: 'read', scope: sentBefore })).toBe(false)
    expect(backofficeAccessRefused.value).toBe(0)
  })

  it('un effacement remet le compteur à zéro', () => {
    markBackofficeAccessRefused(readSentNow())

    clearBackofficeAccessRefusal()

    expect(backofficeAccessRefused.value).toBe(0)
  })

  it("au montage, une écriture comptée garde le compteur et se dit « enregistrement précédent » ; le périmètre ne bouge pas (R64)", () => {
    const sentFromThisPage = refusalScope()
    markBackofficeAccessRefused({ kind: 'write' })

    settleBackofficeAccessRefusalsOnMount()

    expect(backofficeAccessRefused.value).toBe(1)
    expect(earlierWriteRefused.value).toBe(true)
    expect(refusalScope()).toBe(sentFromThisPage)
    expect(markBackofficeAccessRefused({ kind: 'read', scope: sentFromThisPage })).toBe(true)
  })

  it("au montage, un refus de lecture seul est oublié : compteur à zéro, rien à dire, périmètre inchangé (R64)", () => {
    const sentFromThisPage = refusalScope()
    markBackofficeAccessRefused({ kind: 'read', scope: sentFromThisPage })

    settleBackofficeAccessRefusalsOnMount()

    expect(backofficeAccessRefused.value).toBe(0)
    expect(earlierWriteRefused.value).toBe(false)
    expect(refusalScope()).toBe(sentFromThisPage)
  })

  it("un refus compté après le montage remet le texte courant ; un effacement remet les deux à faux (R64)", () => {
    markBackofficeAccessRefused({ kind: 'write' })
    settleBackofficeAccessRefusalsOnMount()
    expect(earlierWriteRefused.value).toBe(true)

    markBackofficeAccessRefused(readSentNow())
    expect(earlierWriteRefused.value).toBe(false)

    // Un effacement oublie aussi l'écriture comptée : le montage suivant n'a rien à dire.
    clearBackofficeAccessRefusal()
    settleBackofficeAccessRefusalsOnMount()
    expect(earlierWriteRefused.value).toBe(false)
    expect(backofficeAccessRefused.value).toBe(0)
  })

  it("un refus d'écriture (`{ kind: 'write' }`) compte toujours, même après une navigation", () => {
    clearBackofficeAccessRefusal()

    expect(markBackofficeAccessRefused({ kind: 'write' })).toBe(true)
    expect(backofficeAccessRefused.value).toBe(1)
  })

  // Deux tests successifs : le premier laisse un refus compté et un périmètre avancé, le second les doit à zéro.
  it('remise à zéro (1/2) : un refus compté et un périmètre avancé', () => {
    markBackofficeAccessRefused(readSentNow())
    clearBackofficeAccessRefusal()
    markBackofficeAccessRefused(readSentNow())
    markBackofficeAccessRefused({ kind: 'write' })
    settleBackofficeAccessRefusalsOnMount()

    expect(backofficeAccessRefused.value).toBe(2)
    expect(earlierWriteRefused.value).toBe(true)
    expect(refusalScope()).toBeGreaterThan(0)
  })

  it("remise à zéro (2/2) : le test précédent n'a rien laissé", () => {
    // Ni l'écriture comptée (drapeau du module) ni le texte « enregistrement précédent » ne survivent à la remise à zéro.
    settleBackofficeAccessRefusalsOnMount()

    expect(backofficeAccessRefused.value).toBe(0)
    expect(earlierWriteRefused.value).toBe(false)
    expect(refusalScope()).toBe(0)
  })
})
