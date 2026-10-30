import { describe, expect, it } from 'vitest'
import { otherLocale, SUPPORTED_LOCALES } from '../../../../src/domain/portfolio/entities/Locale'

describe('otherLocale', () => {
  it("rend l'autre des deux langues supportées", () => {
    expect(otherLocale('fr')).toBe('en')
    expect(otherLocale('en')).toBe('fr')
  })

  it('ne rend jamais la langue reçue, pour chaque langue supportée', () => {
    for (const locale of SUPPORTED_LOCALES) {
      expect(otherLocale(locale)).not.toBe(locale)
    }
  })
})
