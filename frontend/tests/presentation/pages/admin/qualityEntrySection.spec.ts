import { describe, expect, expectTypeOf, it } from 'vitest'
import {
  qualityEntryField,
  qualityEntryFieldsOf,
  fieldRecord,
  QUALITY_PRINCIPLE_FIELD_OPTIONS,
  QUALITY_TRAIT_FIELD_OPTIONS,
  type QualityEntryField,
} from '../../../../src/presentation/pages/admin/qualityEntrySection'
import { QUALITY_PRINCIPLE_PROSE_FIELDS, QUALITY_TRAIT_PROSE_FIELDS } from '../../../../src/presentation/pages/admin/proseFields'

describe('qualityEntryFieldsOf', () => {
  it('rend les champs dans l\'ordre des clés du record, chaque défaut à faux sauf ce que le record déclare', () => {
    const fields = qualityEntryFieldsOf(
      { b: { control: 'textarea' }, a: { control: 'text', copiedToNewVersion: true, column: true } },
      [],
    )

    expect(fields).toEqual([
      { name: 'b', control: 'textarea', prose: false, copiedToNewVersion: false, column: false },
      { name: 'a', control: 'text', prose: false, copiedToNewVersion: true, column: true },
    ])
  })

  it('lit la prose de la liste passée, le nom n\'étant écrit qu\'une fois', () => {
    const fields = qualityEntryFieldsOf({ title: { control: 'text' }, iconKey: { control: 'text' } }, ['title'])

    expect(fields.map((field) => [field.name, field.prose])).toEqual([['title', true], ['iconKey', false]])
  })

  it('refuse à la compilation un nom de prose que le record ne déclare pas', () => {
    // Le contrôle est celui de `vue-tsc` : la directive est elle-même une erreur (« inutilisée ») si la liste n'est plus typée par `F`.
    // @ts-expect-error `bogus` n'est pas un champ de ce record
    const fields = qualityEntryFieldsOf({ title: { control: 'text' } }, ['bogus'])

    expect(fields.map((field) => field.prose)).toEqual([false])
  })

  it('garde les noms du record quand la liste de prose en nomme moins (elle ne rétrécit pas F)', () => {
    const fields = qualityEntryFieldsOf({ title: { control: 'text' }, iconKey: { control: 'text' } }, ['title'] as const)

    expectTypeOf(fields).toEqualTypeOf<readonly QualityEntryField<'title' | 'iconKey'>[]>()
  })
})

describe('fieldRecord', () => {
  it('rend un champ par clé du record, lu par la fonction donnée', () => {
    expect(fieldRecord({ a: 1, b: 2 }, (name) => name.toUpperCase())).toEqual({ a: 'A', b: 'B' })
  })

  it('ne lit que les clés : les valeurs du record ne sont jamais passées à la fonction', () => {
    const seen: string[] = []

    fieldRecord({ title: { control: 'text' }, label: { control: 'text' } }, (name) => {
      seen.push(name)

      return ''
    })

    expect(seen).toEqual(['title', 'label'])
  })
})

describe('qualityEntryField', () => {
  it('lit la prose de la liste de proseFields.ts, le nom n\'étant écrit qu\'une fois', () => {
    const options = { control: 'text', copiedToNewVersion: false, column: false } as const

    expect(qualityEntryField<'title' | 'iconKey'>('title', { ...options, proseFields: ['title'] }).prose).toBe(true)
    expect(qualityEntryField<'title' | 'iconKey'>('iconKey', { ...options, proseFields: ['title'] }).prose).toBe(false)
  })
})

describe('les champs constants des deux sections', () => {
  const principleFields = qualityEntryFieldsOf(QUALITY_PRINCIPLE_FIELD_OPTIONS, QUALITY_PRINCIPLE_PROSE_FIELDS)
  const traitFields = qualityEntryFieldsOf(QUALITY_TRAIT_FIELD_OPTIONS, QUALITY_TRAIT_PROSE_FIELDS)

  it('principes : les noms, la prose de proseFields.ts, ce qui est recopié et la colonne', () => {
    expect(principleFields.map((field) => field.name)).toEqual(['title', 'description', 'iconKey'])
    expect(principleFields.filter((field) => field.prose).map((field) => field.name)).toEqual([...QUALITY_PRINCIPLE_PROSE_FIELDS])
    expect(principleFields.filter((field) => field.copiedToNewVersion).map((field) => field.name)).toEqual(['iconKey'])
    expect(principleFields.filter((field) => field.column).map((field) => field.name)).toEqual(['iconKey'])
    expect(principleFields.map((field) => field.control)).toEqual(['text', 'textarea', 'text'])
  })

  it('traits : un libellé, prose, rien de recopié, aucune colonne', () => {
    expect(traitFields.map((field) => field.name)).toEqual(['label'])
    expect(traitFields.filter((field) => field.prose).map((field) => field.name)).toEqual([...QUALITY_TRAIT_PROSE_FIELDS])
    expect(traitFields.some((field) => field.copiedToNewVersion || field.column)).toBe(false)
  })
})
