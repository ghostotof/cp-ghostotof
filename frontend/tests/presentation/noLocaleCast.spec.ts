import { describe, expect, it } from 'vitest'
import { withoutComments } from '../support/sourceText'
import { SRC_SCRIPTS, SRC_TEMPLATE_FRAGMENTS } from '../support/srcSources'

/**
 * Garde de source (#748, R213, D2, St17-9 ; étendue en R217) : une `string` ne devient `Locale` que par `isSupportedLocale`,
 * jamais par `as Locale`, et la langue d'interface de vue-i18n, une `string` elle aussi, ne se **lit** qu'à un endroit. Elle se
 * lit par `useCurrentLocale()` (`application/shared/`), qui ramène une langue hors du site à la langue par défaut ; la langue
 * d'une route par `routeLocale()`. Le fichier garde son nom d'origine : la règle `frontend.md` et les docblocks le citent.
 *
 * Deux relevés, chacun une égalité (non un plafond) : un fichier de plus fait rougir la garde, et un fichier qui s'en défait
 * aussi, jusqu'à ce que la liste le retire.
 * - `REMAINING_LOCALE_CASTS` : les fichiers qui portent l'assertion. Vide depuis R214, qui valide la langue à la frontière des
 *   dépôts d'admin (`supportedLocaleOf`) : une entrée qui y reviendrait est un retour à la conversion sans jugement, non une
 *   exception à lister.
 * - `LOCALE_READERS` : les fichiers qui lisent la langue d'interface, sans `as`, ce qui donnait deux adresses construites d'une
 *   valeur brute (`CookieNotice.vue`, `TechnologiesSection.vue`, R213). Seul l'accesseur y figure.
 *
 * Ce que `readsInterfaceLocale` lit : `locale` pris d'un `useI18n(…)` par déstructuration ou par `.locale`, le compositeur lié
 * tout entier à un nom (`const i18n = useI18n()`, d'où `.locale` s'atteint), `$i18n` dans un gabarit, et `.global.locale` d'une
 * instance de vue-i18n ou sa déstructuration. L'**écriture** `….global.locale.value = x` n'est pas une lecture : le routeur la
 * pose à chaque navigation (`syncLocale`) et la garde la distingue par sa forme, sans fichier d'exception.
 * Non vu, tenu par la relecture : `i18n.global` lié tout entier à un nom puis lu (`const g = i18n.global`) ; `locale` lu d'un
 * objet qui n'est ni un `useI18n(…)` ni un `.global` écrits tels quels (un compositeur reçu en paramètre) ; une langue lue de la
 * route ou du navigateur, qui n'est pas la langue d'interface.
 */
const REMAINING_LOCALE_CASTS: readonly string[] = []
const LOCALE_READERS: readonly string[] = ['application/shared/useCurrentLocale.ts']

/** Le code d'un fichier de `src/` : son script sans commentaire, le gabarit d'un `.vue` compris. */
function codeOf(path: string): string {
  return [withoutComments(SRC_SCRIPTS[path] ?? ''), ...(SRC_TEMPLATE_FRAGMENTS[path] ?? [])].join('\n')
}

/** Vrai si le code porte une assertion `as Locale` (hors commentaire ; le gabarit d'un `.vue` compris). */
function castsToLocale(path: string): boolean {
  return /\bas\s+Locale\b/.test(codeOf(path))
}

/** La pose de la langue (`i18n.global.locale.value = x`, non `==`) : une écriture, retirée avant de chercher une lecture. */
const LOCALE_WRITE = /\.global\s*\.\s*locale\s*\.\s*value\s*=(?!=)/g
const INTERFACE_LOCALE_READS: readonly RegExp[] = [
  /\{[^}]*\blocale\b[^}]*\}\s*=\s*useI18n\s*\(/,
  /\buseI18n\s*\([^)]*\)\s*\??\.\s*locale\b/,
  /\b(?:const|let|var)\s+[\w$]+\s*=\s*useI18n\s*\(/,
  /\$i18n\b/,
  /\.global\s*\??\.\s*locale\b/,
  /\{[^}]*\blocale\b[^}]*\}\s*=\s*[\w$.]*\bglobal\b/,
]

/** Vrai si le code lit la langue d'interface de vue-i18n (hors commentaire), sa pose exceptée. */
function readsInterfaceLocale(code: string): boolean {
  const withoutWrites = code.replace(LOCALE_WRITE, '')

  return INTERFACE_LOCALE_READS.some((read) => read.test(withoutWrites))
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

  it('les fichiers qui portent `as Locale` sont exactement ceux de la liste, vide depuis R214', () => {
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

describe('la langue d\'interface ne se lit que par useCurrentLocale()', () => {
  it('le détecteur voit chaque forme de lecture, pas la pose, pas un autre usage de useI18n (#748, R217)', () => {
    const detect = (code: string): boolean => readsInterfaceLocale(withoutComments(code))

    // Les lectures.
    expect(detect('const { t, locale } = useI18n()')).toBe(true)
    expect(detect('const { locale: current } = useI18n({ useScope: \'global\' })')).toBe(true)
    expect(detect('const { t, locale } =\n  useI18n()')).toBe(true)
    expect(detect('const current = useI18n().locale')).toBe(true)
    expect(detect('const i18n = useI18n()\nconst l = i18n.locale.value')).toBe(true)
    expect(detect('const l = i18n.global.locale.value')).toBe(true)
    expect(detect('const { locale } = i18n.global')).toBe(true)
    expect(detect('const same = i18n.global.locale.value === next ? 1 : 2')).toBe(true)
    expect(detect('`${$i18n.locale}`')).toBe(true)
    expect(detect('i18n.global.locale.value = i18n.global.locale.value')).toBe(true)
    // La pose (ce que fait le routeur) n'est pas une lecture ; la comparaison `==` en est une.
    expect(detect('i18n.global.locale.value = locale')).toBe(false)
    expect(detect('i18n.global.locale.value == locale')).toBe(true)
    // Les autres usages : `t`, `n`, `d`, un commentaire.
    expect(detect('const { t } = useI18n()')).toBe(false)
    expect(detect('const { t, te } = useI18n({ useScope: \'local\' })')).toBe(false)
    expect(detect('document.title = i18n.global.t(titleKey)')).toBe(false)
    expect(detect('// const { locale } = useI18n()')).toBe(false)
    expect(detect('const { locale: _unused } = useRoute()')).toBe(false)
  })

  it('les fichiers qui lisent la langue d\'interface sont exactement ceux de la liste (#748, R217)', () => {
    const reading = Object.keys(SRC_SCRIPTS).filter((path) => readsInterfaceLocale(codeOf(path))).sort()
    const expected = [...LOCALE_READERS].sort()

    const surplus = reading.filter((path) => !expected.includes(path))
    const missing = expected.filter((path) => !reading.includes(path))

    expect(
      { surplus, missing },
      `lecture hors de useCurrentLocale : ${surplus.join(', ') || 'aucune'} ; listés sans lecture : ${missing.join(', ') || 'aucun'}`,
    ).toEqual({ surplus: [], missing: [] })
  })
})
