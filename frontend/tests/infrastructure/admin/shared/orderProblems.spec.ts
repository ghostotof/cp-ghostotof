import { describe, expect, it } from 'vitest'
import { STALE_ORDER_PROBLEM_TYPES } from '../../../../src/infrastructure/admin/shared/orderProblems'

describe('orderProblems', () => {
  it("porte les deux slugs d'ordre obsolète de la spec 0004 (D4)", () => {
    expect(STALE_ORDER_PROBLEM_TYPES).toEqual(['unknown-order-entry', 'incomplete-order'])
  })
})
