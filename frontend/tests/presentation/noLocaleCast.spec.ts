import { describe, expect, it } from 'vitest'
import { withoutComments } from '../support/sourceText'
import { SRC_SCRIPTS, SRC_TEMPLATE_FRAGMENTS } from '../support/srcSources'

/**
 * Garde de source (#748, R213, D2, St17-9) : une `string` ne devient `Locale` que par `isSupportedLocale`, jamais par
 * `as Locale`. La langue d'interface se lit par `useCurrentLocale()` (`application/shared/`), qui ramène une langue hors du
 * site à la langue par défaut ; la langue d'une route par `routeLocale()`.
 *
 * Les fichiers qui portent encore l'assertion sont **exactement** `REMAINING_LOCALE_CASTS` (une égalité, non un plafond) :
 * un fichier de plus fait rougir la garde, et un fichier qui s'en défait aussi, jusqu'à ce que la liste le retire. R214 la
 * vide, en validant la langue à la frontière des dépôts d'admin.
 */
const REMAINING_LOCALE_CASTS: readonly string[] = [
  'domain/admin/shared/ordering/groupByTranslationGroup.ts',
  'infrastructure/admin/about/HttpAdminAboutMeCardRepository.ts',
  'infrastructure/admin/about/HttpAdminAboutSettingsRepository.ts',
  'infrastructure/admin/about/HttpAdminAboutSiteCardRepository.ts',
  'infrastructure/admin/quality/HttpAdminQualityPrincipleRepository.ts',
  'infrastructure/admin/quality/HttpAdminQualityTraitRepository.ts',
]

/** Vrai si le code porte une assertion `as Locale` (hors commentaire ; le gabarit d'un `.vue` compris). */
function castsToLocale(path: string): boolean {
  const code = [withoutComments(SRC_SCRIPTS[path] ?? ''), ...(SRC_TEMPLATE_FRAGMENTS[path] ?? [])].join('\n')
  return /\bas\s+Locale\b/.test(code)
}

describe('aucun `as Locale` dans src/', () => {
  it('lit bien les sources (la glob ne doit pas être vide)', () => {
    expect(Object.keys(SRC_SCRIPTS).length).toBeGreaterThan(100)
  })

  it('le détecteur voit une assertion, pas un commentaire', () => {
    const detect = (code: string): boolean => /\bas\s+Locale\b/.test(withoutComments(code))

    expect(detect('const l = locale.value as Locale')).toBe(true)
    expect(detect('const l = (x as\n  Locale)')).toBe(true)
    expect(detect('// jamais `as Locale`')).toBe(false)
    expect(detect('const l = x as LocaleKey')).toBe(false)
  })

  it('les fichiers qui portent `as Locale` sont exactement ceux de la liste (R214 la vide)', () => {
    const casting = Object.keys(SRC_SCRIPTS).filter(castsToLocale).sort()
    const expected = [...REMAINING_LOCALE_CASTS].sort()

    const surplus = casting.filter((path) => !expected.includes(path))
    const missing = expected.filter((path) => !casting.includes(path))

    expect(
      { surplus, missing },
      `as Locale en trop : ${surplus.join(', ') || 'aucun'} ; listés sans assertion : ${missing.join(', ') || 'aucun'}`,
    ).toEqual({ surplus: [], missing: [] })
  })
})
