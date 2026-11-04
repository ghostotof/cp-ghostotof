import { describe, expect, it } from 'vitest'
import { swapLocalePrefix } from '../../../src/presentation/router/localePrefix'

describe('swapLocalePrefix', () => {
  it('remplace le premier segment de langue, le reste du chemin gardé', () => {
    expect(swapLocalePrefix('/fr/about', 'fr', 'en')).toBe('/en/about')
  })

  it('remplace un chemin réduit à la langue', () => {
    expect(swapLocalePrefix('/fr', 'fr', 'en')).toBe('/en')
  })

  it('garde la query et le fragment qui suivent la langue', () => {
    expect(swapLocalePrefix('/fr?x=1', 'fr', 'en')).toBe('/en?x=1')
    expect(swapLocalePrefix('/fr#a', 'fr', 'en')).toBe('/en#a')
  })

  it('un premier segment qui commence seulement par la langue n’a pas de préfixe : `/frogs` ne devient pas `/engs`', () => {
    expect(swapLocalePrefix('/frogs', 'fr', 'en')).toBeNull()
  })

  it('un chemin préfixé par une autre langue que `from` n’a pas de préfixe à changer', () => {
    expect(swapLocalePrefix('/en/about', 'fr', 'en')).toBeNull()
  })
})
