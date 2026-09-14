import { describe, expect, it } from 'vitest'
import { moveKey } from '../../../../../src/domain/admin/shared/ordering/moveKey'

describe('moveKey', () => {
  it('déplace une clé vers une position ultérieure', () => {
    expect(moveKey(['a', 'b', 'c'], 0, 2)).toEqual(['b', 'c', 'a'])
  })

  it('déplace une clé vers une position antérieure', () => {
    expect(moveKey(['a', 'b', 'c'], 2, 0)).toEqual(['c', 'a', 'b'])
  })

  it('renvoie une copie inchangée quand from === to', () => {
    const keys = ['a', 'b', 'c']
    const result = moveKey(keys, 1, 1)

    expect(result).toEqual(keys)
    expect(result).not.toBe(keys)
  })

  it('renvoie une copie inchangée hors bornes', () => {
    const keys = ['a', 'b', 'c']

    expect(moveKey(keys, -1, 1)).toEqual(keys)
    expect(moveKey(keys, 0, 5)).toEqual(keys)
    expect(moveKey(keys, 5, 0)).toEqual(keys)
  })

  it("ne modifie pas le tableau d'origine", () => {
    const keys = ['a', 'b', 'c']
    moveKey(keys, 0, 2)

    expect(keys).toEqual(['a', 'b', 'c'])
  })
})
