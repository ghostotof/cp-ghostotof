import { describe, expect, it } from 'vitest'
import { groupByTranslationGroup } from '../../../../../src/domain/admin/shared/ordering/groupByTranslationGroup'

interface Entry {
  translationGroup: string
  locale: string
  position: number
}

function entry(props: Entry): Entry {
  return props
}

describe('groupByTranslationGroup', () => {
  it('regroupe les entrées du même groupe en une ligne, triée par position', () => {
    const entries: Entry[] = [
      entry({ translationGroup: 'g2', locale: 'fr', position: 1 }),
      entry({ translationGroup: 'g1', locale: 'fr', position: 0 }),
      entry({ translationGroup: 'g1', locale: 'en', position: 0 }),
      entry({ translationGroup: 'g2', locale: 'en', position: 1 }),
    ]

    const rows = groupByTranslationGroup(entries, ['fr', 'en'])

    expect(rows.map((row) => row.key)).toEqual(['g1', 'g2'])
    expect(rows[0].position).toBe(0)
    expect(rows[1].position).toBe(1)
    expect(rows[0].byLocale.fr?.translationGroup).toBe('g1')
    expect(rows[0].byLocale.en?.translationGroup).toBe('g1')
    expect(rows[0].missing).toEqual([])
  })

  it("signale les locales absentes d'un groupe", () => {
    const entries: Entry[] = [entry({ translationGroup: 'g1', locale: 'fr', position: 0 })]

    const rows = groupByTranslationGroup(entries, ['fr', 'en'])

    expect(rows).toHaveLength(1)
    expect(rows[0].missing).toEqual(['en'])
    expect(rows[0].byLocale.en).toBeUndefined()
  })

  it("départage une position égale par ordre de première apparition", () => {
    const entries: Entry[] = [
      entry({ translationGroup: 'g1', locale: 'fr', position: 0 }),
      entry({ translationGroup: 'g2', locale: 'fr', position: 0 }),
    ]

    const rows = groupByTranslationGroup(entries, ['fr', 'en'])

    expect(rows.map((row) => row.key)).toEqual(['g1', 'g2'])
  })

  it('renvoie un tableau vide pour un périmètre vide', () => {
    expect(groupByTranslationGroup([], ['fr', 'en'])).toEqual([])
  })

  it('ignore une locale rendue par une entrée mais absente du périmètre demandé', () => {
    const entries: Entry[] = [
      entry({ translationGroup: 'g1', locale: 'fr', position: 0 }),
      entry({ translationGroup: 'g1', locale: 'de', position: 0 }),
    ]

    const rows = groupByTranslationGroup(entries, ['fr', 'en'])

    expect(rows[0].byLocale).toEqual({ fr: entries[0] })
    expect(rows[0].missing).toEqual(['en'])
  })
})
