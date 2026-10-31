import { describe, expect, it } from 'vitest'
import { sameFormIdentity, type FormIdentity } from '../../../../src/domain/admin/translation/entities/FormIdentity'

const BASE: FormIdentity = { entry: 'a', locale: 'fr', group: 'g', generation: 0 }

describe('sameFormIdentity', () => {
  it("deux identités égales champ par champ sont la même, quel que soit l'ordre des clés (#748, R137)", () => {
    // Les mêmes valeurs, les clés écrites dans un autre ordre : une comparaison sérialisée dirait « différentes ».
    const reordered: FormIdentity = { generation: 0, group: 'g', locale: 'fr', entry: 'a' }

    expect(sameFormIdentity(BASE, reordered)).toBe(true)
    expect(sameFormIdentity(BASE, { ...BASE })).toBe(true)
  })

  it.each<[string, Partial<FormIdentity>]>([
    ['entry', { entry: 'b' }],
    ['entry (une création contre une entrée)', { entry: null }],
    ['locale', { locale: 'en' }],
    ['group', { group: 'h' }],
    ['group (aucun groupe)', { group: null }],
    ['generation', { generation: 1 }],
  ])('une identité qui diffère par %s est une autre (#748, R137)', (_field, change) => {
    expect(sameFormIdentity(BASE, { ...BASE, ...change })).toBe(false)
    expect(sameFormIdentity({ ...BASE, ...change }, BASE)).toBe(false)
  })
})
