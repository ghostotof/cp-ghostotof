import { describe, expect, it } from 'vitest'
import { orderRowsByDraft } from '../../../../../src/domain/admin/shared/ordering/orderRowsByDraft'
import type { TranslationGroupRow } from '../../../../../src/domain/admin/shared/ordering/groupByTranslationGroup'

function row(key: string, position: number): TranslationGroupRow<{ title: string }> {
  return { key, position, byLocale: { fr: { title: key } }, missing: ['en'] }
}

const FIRST = row('a', 0)
const SECOND = row('b', 1)
const THIRD = row('c', 2)

describe('orderRowsByDraft', () => {
  it("rend les lignes dans l'ordre du brouillon, pas dans celui du serveur", () => {
    expect(orderRowsByDraft([FIRST, SECOND, THIRD], ['c', 'a', 'b'])).toEqual([THIRD, FIRST, SECOND])
  })

  it('ignore une clé du brouillon qui ne correspond à aucune ligne', () => {
    // Entre un déplacement et le rechargement qui suit un enregistrement, les
    // deux listes peuvent diverger d'un instant : une ligne fantôme y serait
    // pire qu'une ligne absente. Le cas durable relève du serveur (D4).
    expect(orderRowsByDraft([FIRST, SECOND], ['a', 'disparue', 'b'])).toEqual([FIRST, SECOND])
  })

  it("omet une ligne absente du brouillon plutôt que de l'ajouter à la fin", () => {
    expect(orderRowsByDraft([FIRST, SECOND, THIRD], ['b'])).toEqual([SECOND])
  })

  it('rend une liste vide sur un brouillon vide, sans lever', () => {
    expect(orderRowsByDraft([FIRST], [])).toEqual([])
    expect(orderRowsByDraft([], [])).toEqual([])
  })
})
