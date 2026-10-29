import { afterEach, describe, expect, it } from 'vitest'
import {
  backofficeAccessRefused,
  clearBackofficeAccessRefusal,
  markBackofficeAccessRefused,
  refusalScope,
} from '../../../../src/application/admin/shared/backofficeAccessRefusal'

/**
 * Le compteur des refus réels et son périmètre (#748, R28, CR3-8) : une navigation (un effacement) avance le
 * périmètre, et un refus relevé sous un périmètre révolu ne compte pas.
 */
describe('backofficeAccessRefusal', () => {
  afterEach(clearBackofficeAccessRefusal)

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
})
