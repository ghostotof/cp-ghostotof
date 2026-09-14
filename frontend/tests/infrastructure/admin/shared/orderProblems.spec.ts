import { describe, expect, it } from 'vitest'
import { STALE_ORDER_PROBLEM_TYPES, hasProblemType } from '../../../../src/infrastructure/admin/shared/orderProblems'

describe('orderProblems', () => {
  it("porte les deux slugs d'ordre obsolète de la spec 0004 (D4)", () => {
    expect(STALE_ORDER_PROBLEM_TYPES).toEqual(['unknown-order-entry', 'incomplete-order'])
  })

  it('reconnaît un type de problème par son slug, préfixe compris', () => {
    expect(hasProblemType({ type: '/errors/incomplete-order' }, 'incomplete-order')).toBe(true)
    expect(hasProblemType({ type: 'https://api.example.test/errors/incomplete-order' }, 'incomplete-order')).toBe(true)
  })

  it('exige le segment entier, jamais une simple fin de chaîne', () => {
    // « order » est bien un suffixe de « /errors/incomplete-order », mais pas un
    // segment : sans le `/` du test, deux slugs voisins seraient confondus.
    expect(hasProblemType({ type: '/errors/incomplete-order' }, 'order')).toBe(false)
  })

  it('renvoie false sur un autre type, sur un type absent et sur un corps vide', () => {
    expect(hasProblemType({ type: '/errors/translation-already-exists' }, 'incomplete-order')).toBe(false)
    expect(hasProblemType({ detail: 'Validation failed' }, 'incomplete-order')).toBe(false)
    expect(hasProblemType({}, 'incomplete-order')).toBe(false)
  })
})
