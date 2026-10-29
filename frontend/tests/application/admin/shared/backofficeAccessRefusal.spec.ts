import { describe, expect, it } from 'vitest'
import {
  backofficeAccessRefused,
  clearBackofficeAccessRefusal,
  markBackofficeAccessRefused,
  refusalScope,
} from '../../../../src/application/admin/shared/backofficeAccessRefusal'

/**
 * Le compteur des refus réels et son périmètre (#748, R28, CR3-8) : une navigation (un effacement) avance le
 * périmètre, et un refus relevé sous un périmètre révolu ne compte pas. Une écriture (`null`) n'est jamais périmée
 * (R38). Aucun `afterEach` ici : `tests/support/setup.ts` remet l'état à zéro avant chaque test, et les deux
 * derniers tests le prouvent.
 */
describe('backofficeAccessRefusal', () => {
  it('un refus du périmètre courant compte et le dit', () => {
    expect(markBackofficeAccessRefused(refusalScope())).toBe(true)
    expect(markBackofficeAccessRefused(refusalScope())).toBe(true)

    expect(backofficeAccessRefused.value).toBe(2)
  })

  it('un effacement avance le périmètre : le refus relevé avant ne compte plus, rien ne change', () => {
    const sentBefore = refusalScope()
    clearBackofficeAccessRefusal()

    expect(refusalScope()).not.toBe(sentBefore)
    expect(markBackofficeAccessRefused(sentBefore)).toBe(false)
    expect(backofficeAccessRefused.value).toBe(0)
  })

  it('un effacement remet le compteur à zéro', () => {
    markBackofficeAccessRefused(refusalScope())

    clearBackofficeAccessRefusal()

    expect(backofficeAccessRefused.value).toBe(0)
  })

  it("un refus d'écriture (périmètre nul) compte toujours, même après une navigation", () => {
    clearBackofficeAccessRefusal()

    expect(markBackofficeAccessRefused(null)).toBe(true)
    expect(backofficeAccessRefused.value).toBe(1)
  })

  // Deux tests successifs : le premier laisse un refus compté et un périmètre avancé, le second les doit à zéro.
  it('remise à zéro (1/2) : un refus compté et un périmètre avancé', () => {
    markBackofficeAccessRefused(refusalScope())
    clearBackofficeAccessRefusal()
    markBackofficeAccessRefused(refusalScope())

    expect(backofficeAccessRefused.value).toBe(1)
    expect(refusalScope()).toBeGreaterThan(0)
  })

  it("remise à zéro (2/2) : le test précédent n'a rien laissé", () => {
    expect(backofficeAccessRefused.value).toBe(0)
    expect(refusalScope()).toBe(0)
  })
})
