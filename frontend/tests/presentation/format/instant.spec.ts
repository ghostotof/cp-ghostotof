import { describe, expect, it } from 'vitest'
import { formatInstant } from '../../../src/presentation/format/instant'

/**
 * ICU change l'espace avant « AM » (espace fine insécable depuis ICU 72) et la
 * virgule ou le « à » entre date et heure selon sa version : on compare sur
 * un texte aux espaces normalisés, pas sur les octets d'une version d'ICU.
 */
function normalized(text: string): string {
  return text.replace(/[  ]/g, ' ')
}

describe('formatInstant', () => {
  it('écrit le jour et l\'heure en français', () => {
    const text = normalized(formatInstant('2026-10-28T09:05:00+01:00', 'fr'))

    expect(text).toContain('28')
    expect(text).toContain('oct')
    expect(text).toContain('2026')
    expect(text).toContain('09:05')
  })

  it('écrit le jour et l\'heure en anglais', () => {
    const text = normalized(formatInstant('2026-10-28T09:05:00+01:00', 'en'))

    expect(text).toContain('Oct')
    expect(text).toContain('28')
    expect(text).toContain('2026')
    expect(text).toContain('9:05 AM')
  })

  it('lit l\'heure à Paris, quel que soit le fuseau de la machine ou du décalage reçu', () => {
    // 07:30 UTC le 28 octobre 2026 : Paris est alors en heure d'hiver (UTC+1, le
    // passage a eu lieu le 25), soit 08:30.
    expect(normalized(formatInstant('2026-10-28T07:30:00+00:00', 'fr'))).toContain('08:30')
    // Même instant écrit avec un autre décalage : même affichage.
    expect(normalized(formatInstant('2026-10-28T02:30:00-05:00', 'fr'))).toContain('08:30')
  })

  it('suit l\'heure d\'été de Paris (UTC+2) : le décalage n\'est pas figé', () => {
    expect(normalized(formatInstant('2026-07-14T10:00:00+00:00', 'fr'))).toContain('12:00')
  })

  it('rend la chaîne brute plutôt qu\'« Invalid Date » quand l\'instant est illisible', () => {
    expect(formatInstant('pas un instant', 'fr')).toBe('pas un instant')
    expect(formatInstant('', 'en')).toBe('')
  })
})
