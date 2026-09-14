import { describe, expect, it } from 'vitest'
import { groupByTranslationGroup, type TranslationGroupRow } from '../../../../../src/domain/admin/shared/ordering/groupByTranslationGroup'
import { firstEntry, hasSibling, rowLines, translationGroupOptions } from '../../../../../src/domain/admin/shared/ordering/translationGroupSelection'
import { LOCALE_NATIVE_NAMES, SUPPORTED_LOCALES } from '../../../../../src/domain/portfolio/entities/Locale'

interface Entry {
  id: string
  locale: string
  translationGroup: string
  title: string
  position: number
}

const FR_ONE: Entry = { id: '1', locale: 'fr', translationGroup: 'g1', title: 'Panne FR', position: 0 }
const EN_ONE: Entry = { id: '2', locale: 'en', translationGroup: 'g1', title: 'Outage EN', position: 0 }
const FR_TWO: Entry = { id: '3', locale: 'fr', translationGroup: 'g2', title: 'Solo FR', position: 1 }

const ALL: Entry[] = [FR_ONE, EN_ONE, FR_TWO]

const labelOf = (entry: Entry): string => `${entry.locale.toUpperCase()} · ${entry.title}`

describe('translationGroupOptions', () => {
  it("propose les entrées d'autres locales dont le groupe n'a pas encore la locale du formulaire", () => {
    const options = translationGroupOptions(ALL, 'en', '', null, labelOf)

    // g1 a déjà une entrée en 'en' (EN_ONE) : sa version FR n'est pas une
    // « version de » possible. g2 (FR_TWO) n'a pas d'entrée en 'en' : proposée.
    expect(options).toEqual([{ value: 'g2', label: 'FR · Solo FR' }])
  })

  it('conserve le groupe déjà retenu même s\'il a déjà une traduction dans la locale du formulaire', () => {
    const options = translationGroupOptions(ALL, 'en', 'g1', null, labelOf)

    expect(options).toEqual([
      { value: 'g1', label: 'FR · Panne FR' },
      { value: 'g2', label: 'FR · Solo FR' },
    ])
  })

  it("n'exclut pas un groupe pour sa propre traduction déjà existante, quand celle-ci est l'entrée en cours d'édition", () => {
    const options = translationGroupOptions(ALL, 'en', '', EN_ONE.id, labelOf)

    // EN_ONE est l'entrée éditée : elle ne compte plus comme « occupant » la
    // locale 'en' pour son groupe g1, qui redevient donc une option valide.
    expect(options).toEqual([
      { value: 'g1', label: 'FR · Panne FR' },
      { value: 'g2', label: 'FR · Solo FR' },
    ])
  })

  it("n'inclut jamais une entrée de la locale du formulaire elle-même", () => {
    // formTranslationGroup='g1' garde g1 dans les options (cf. test précédent) ;
    // seule EN_ONE (locale 'en') peut y figurer, FR_ONE et FR_TWO étant tous
    // deux en 'fr', la locale du formulaire.
    const options = translationGroupOptions(ALL, 'fr', 'g1', null, labelOf)

    expect(options).toEqual([{ value: 'g1', label: 'EN · Outage EN' }])
  })
})

describe('rowLines', () => {
  const rows = groupByTranslationGroup(ALL, SUPPORTED_LOCALES)
  const groupOneRow = rows.find((row) => 'g1' === row.key) as TranslationGroupRow<Entry>
  const groupTwoRow = rows.find((row) => 'g2' === row.key) as TranslationGroupRow<Entry>

  it('produit une ligne par locale du périmètre, entrée présente quand elle existe', () => {
    const lines = rowLines(groupOneRow, SUPPORTED_LOCALES, LOCALE_NATIVE_NAMES)

    expect(lines.map((line) => line.locale)).toEqual(SUPPORTED_LOCALES)
    expect(lines.find((line) => 'fr' === line.locale)?.entry).toBe(FR_ONE)
    expect(lines.find((line) => 'en' === line.locale)?.entry).toBe(EN_ONE)
  })

  it('renvoie entry=null et le nom natif de la langue pour une traduction manquante', () => {
    const lines = rowLines(groupTwoRow, SUPPORTED_LOCALES, LOCALE_NATIVE_NAMES)
    const missing = lines.find((line) => 'en' === line.locale)

    expect(missing?.entry).toBeNull()
    expect(missing?.nativeName).toBe(LOCALE_NATIVE_NAMES.en)
  })
})

describe('firstEntry', () => {
  const rows = groupByTranslationGroup(ALL, SUPPORTED_LOCALES)
  const groupTwoRow = rows.find((row) => 'g2' === row.key) as TranslationGroupRow<Entry>

  it("renvoie la première entrée disponible selon l'ordre des locales", () => {
    expect(firstEntry(groupTwoRow, SUPPORTED_LOCALES)).toBe(FR_TWO)
  })

  it("renvoie null quand aucune locale du périmètre n'a d'entrée", () => {
    const emptyRow: TranslationGroupRow<Entry> = { key: 'g3', position: 0, byLocale: {}, missing: [...SUPPORTED_LOCALES] }

    expect(firstEntry(emptyRow, SUPPORTED_LOCALES)).toBeNull()
  })
})

describe('hasSibling', () => {
  it('vrai quand une autre entrée du même groupe existe', () => {
    expect(hasSibling(ALL, FR_ONE)).toBe(true)
  })

  it('faux pour une entrée seule dans son groupe', () => {
    expect(hasSibling(ALL, FR_TWO)).toBe(false)
  })
})
