import { describe, expect, it } from 'vitest'
import { withoutComments } from '../support/sourceText'
import { SRC_SCRIPTS, SRC_TEMPLATE_FRAGMENTS } from '../support/srcSources'

/**
 * Les gardes de source de la langue (#748) : une `string` ne devient `Locale` que par `isSupportedLocale`, et la langue
 * d'interface de vue-i18n, une `string` elle aussi, ne se **lit** qu'à un endroit. Deux gardes, deux `describe` :
 * - **aucune assertion vers une langue dans `src/`** : `LOCALE_CASTS` liste les formes, lues par la garde et par le cas du
 *   détecteur. La langue se juge à la frontière (`isSupportedLocale`, `supportedLocaleOf` pour les dépôts d'admin) ;
 *   le relevé égale `[]`, et une assertion qui reviendrait est une conversion sans jugement, non une exception à lister.
 * - **la langue d'interface ne se lit que par `useCurrentLocale()`** (`application/shared/`), qui ramène une langue hors du
 *   site à la langue par défaut ; la langue d'une route se lit par `routeLocale()`, celle d'une page par `useRouteLocale()`.
 *   `LOCALE_READERS` liste les fichiers qui lisent la langue d'interface : seul l'accesseur y figure.
 *
 * Chaque relevé est une égalité (non un plafond), par `expectExactly` : un fichier de plus fait rougir la garde, et un fichier
 * qui s'en défait aussi, jusqu'à ce que la liste le retire.
 *
 * Ce que `readsInterfaceLocale` lit : `locale` pris d'un `useI18n(…)` par déstructuration ou par `.locale`, le compositeur lié
 * tout entier à un nom (`const i18n = useI18n()`, d'où `.locale` s'atteint), `$i18n` dans un gabarit, et `.global.locale` d'une
 * instance de vue-i18n ou sa déstructuration. L'**écriture** `….global.locale.value = x` n'est pas une lecture : le routeur et
 * le sélecteur la posent (`applyInterfaceLocale`) et la garde la distingue par sa forme, sans fichier d'exception.
 * Non vu, tenu par la relecture : `i18n.global` lié tout entier à un nom puis lu (`const g = i18n.global`) ; `locale` lu d'un
 * objet qui n'est ni un `useI18n(…)` ni un `.global` écrits tels quels (un compositeur reçu en paramètre) ; une langue lue de la
 * route ou du navigateur, qui n'est pas la langue d'interface.
 */
const LOCALE_READERS: readonly string[] = ['application/shared/useCurrentLocale.ts']

/**
 * Les formes d'une assertion vers une langue, la seule liste. `as` suivi d'un type qui contient le mot `Locale` avant la
 * fin de la ligne ou de l'instruction (`as Locale`, `as Ref<Locale>`, `as readonly Locale[]`, `as Locale | null` ;
 * `as LocaleKey` n'en est pas une). Ne sont pas des assertions : `as const` (`as const satisfies Record<Locale, …>`) et le
 * `as` d'alias dans les accolades d'un `import` ou d'un `export` (`import { X as Y, type Locale }`). `<Locale>` n'est une
 * assertion que s'il ne suit ni un identifiant ni `>`, `)`, `]` : `ComputedRef<Locale>`, `computed<Locale>(` et
 * `ref<Locale>(` sont des types génériques.
 */
const LOCALE_CASTS: readonly RegExp[] = [
  /(?<!\b(?:import|export)\b[^{};]*\{[^}]*)\bas\s+(?!const\b)[^;\n]*?\bLocale\b/,
  /(?<![\w$>)\]])<\s*Locale\s*>/,
  /\bas\s+['"](?:fr|en)['"]/,
  /\bas\s+typeof\s+DEFAULT_LOCALE\b/,
  /\bas\s+\(?\s*typeof\s+SUPPORTED_LOCALES\b/,
]

/** Vrai si le code (déjà sans commentaire) porte l'une des assertions de `LOCALE_CASTS`. */
function castsToLocale(code: string): boolean {
  return LOCALE_CASTS.some((cast) => cast.test(code))
}

/** Le code d'un fichier de `src/` : son script sans commentaire, le gabarit d'un `.vue` compris. */
function codeOf(path: string): string {
  return [withoutComments(SRC_SCRIPTS[path] ?? ''), ...(SRC_TEMPLATE_FRAGMENTS[path] ?? [])].join('\n')
}

/** Les fichiers de `src/` dont le code satisfait `matches`, triés. */
function filesMatching(matches: (code: string) => boolean): string[] {
  return Object.keys(SRC_SCRIPTS).filter((path) => matches(codeOf(path))).sort()
}

/** Égalité d'un relevé et de sa liste : le fichier en trop et le fichier listé sans la forme, nommés au message. */
function expectExactly(found: readonly string[], expected: readonly string[], labels: { surplus: string; missing: string }): void {
  const surplus = found.filter((path) => !expected.includes(path))
  const missing = expected.filter((path) => !found.includes(path))

  expect(
    { surplus, missing },
    `${labels.surplus} : ${surplus.join(', ') || 'aucun'} ; ${labels.missing} : ${missing.join(', ') || 'aucun'}`,
  ).toEqual({ surplus: [], missing: [] })
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

describe('aucune assertion vers une langue dans src/', () => {
  it('lit bien les sources (la glob ne doit pas être vide)', () => {
    expect(Object.keys(SRC_SCRIPTS).length).toBeGreaterThan(100)
  })

  it('le détecteur voit une assertion, pas un commentaire', () => {
    const detect = (code: string): boolean => castsToLocale(withoutComments(code))

    expect(detect('const l = locale.value as Locale')).toBe(true)
    expect(detect('const l = (x as\n  Locale)')).toBe(true)
    expect(detect('// jamais `as Locale`')).toBe(false)
    expect(detect('const l = x as LocaleKey')).toBe(false)
    // Les autres formes d'assertion vers une langue.
    expect(detect('const l = <Locale>x')).toBe(true)
    expect(detect("const l = x as 'fr' | 'en'")).toBe(true)
    expect(detect('const l = x as typeof DEFAULT_LOCALE')).toBe(true)
    expect(detect('const l = x as (typeof SUPPORTED_LOCALES)[number]')).toBe(true)
    // Un type qui contient `Locale` après `as` (CR19-1) : une enveloppe, un tableau, une union.
    expect(detect('const r = x as Ref<Locale>')).toBe(true)
    expect(detect('const r = x as ComputedRef<Locale>')).toBe(true)
    expect(detect('const l = x as Array<Locale>')).toBe(true)
    expect(detect('const l = x as readonly Locale[]')).toBe(true)
    expect(detect('const l = x as Locale | null')).toBe(true)
    // `as` et `Locale` séparés par une fin d'instruction ne font pas une assertion.
    expect(detect('const a = x as string;\nconst b: Locale = y')).toBe(false)
    // Un type générique ou annoté n'est pas une assertion.
    expect(detect('const l: ComputedRef<Locale> = x')).toBe(false)
    expect(detect('const l = computed<Locale>(() => x)')).toBe(false)
    expect(detect("const l = ref<Locale>('fr')")).toBe(false)
    expect(detect('const m = new Map<string, Locale>()')).toBe(false)
    expect(detect('const l = (x as unknown as string[])[0]')).toBe(false)
    expect(detect("const names = ['fr', 'en'] as const")).toBe(false)
    // `as const` et l'alias d'un import ou d'un export ne sont pas des assertions (R241 de la vingtième relecture).
    expect(detect("const names = { fr: 'a', en: 'b' } as const satisfies Record<Locale, string>")).toBe(false)
    expect(detect("const names = ['fr', 'en'] as const satisfies readonly Locale[]")).toBe(false)
    expect(detect("import { SUPPORTED_LOCALES as ALL, type Locale } from '../domain/locale'")).toBe(false)
    expect(detect("import type { A as B, Locale } from '../domain/locale'")).toBe(false)
    expect(detect("import Def, { A as B, type Locale } from '../domain/locale'")).toBe(false)
    expect(detect("import {\n  SUPPORTED_LOCALES as ALL,\n  type Locale,\n} from '../domain/locale'")).toBe(false)
    expect(detect("export { ALL as SUPPORTED, type Locale } from '../domain/locale'")).toBe(false)
    // Une assertion reste vue à côté d'un `as const`, d'un alias ou d'un import.
    expect(detect('const l = x as const as Locale')).toBe(true)
    expect(detect("import { A as B } from './a'\nconst l = x as Locale")).toBe(true)
    expect(detect("import { A as B } from './a'\nconst l = x as Ref<Locale>")).toBe(true)
    expect(detect("export { a as b }\nconst l = x as Locale | null")).toBe(true)
  })

  it('aucun fichier de src/ ne convertit une chaîne en Locale par une assertion', () => {
    expectExactly(filesMatching(castsToLocale), [], { surplus: 'assertion en trop', missing: 'listés sans assertion' })
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
    expectExactly(filesMatching(readsInterfaceLocale), LOCALE_READERS, {
      surplus: 'lecture hors de useCurrentLocale',
      missing: 'listés sans lecture',
    })
  })
})
