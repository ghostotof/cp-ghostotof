import { describe, expect, it } from 'vitest'
import {
  entryField,
  fieldsFrom,
  hasEveryField,
  MissingEntryFieldError,
  QUALITY_PRINCIPLE_FIELDS,
  QUALITY_TRAIT_FIELDS,
  requireEveryField,
} from '../../../../src/presentation/pages/admin/qualityEntrySection'
import { QUALITY_PRINCIPLE_PROSE_FIELDS, QUALITY_TRAIT_PROSE_FIELDS } from '../../../../src/presentation/pages/admin/proseFields'

describe('hasEveryField', () => {
  it('est faux quand un nom de la liste manque à la valeur', () => {
    expect(hasEveryField({ title: '' }, ['title', 'description'])).toBe(false)
  })

  it('est vrai quand chaque nom de la liste a une chaîne', () => {
    expect(hasEveryField({ title: '', description: '' }, ['title', 'description'])).toBe(true)
  })

  it('est faux quand un nom porte autre chose qu\'une chaîne', () => {
    expect(hasEveryField({ title: '', description: undefined }, ['title', 'description'])).toBe(false)
  })
})

describe('requireEveryField', () => {
  it('rend l\'objet lui-même quand chaque nom y est', () => {
    const form = { locale: 'fr', title: '', description: '' }

    expect(requireEveryField(form, ['title', 'description'])).toBe(form)
  })

  it('lève une erreur qui nomme le premier champ absent', () => {
    expect(() => requireEveryField({ title: '' }, ['title', 'description', 'iconKey'])).toThrow(MissingEntryFieldError)
    expect(() => requireEveryField({ title: '' }, ['title', 'description', 'iconKey'])).toThrow(/« description »/)
  })
})

describe('fieldsFrom', () => {
  it('rend un champ par nom, lu par la fonction donnée', () => {
    expect(fieldsFrom(['title', 'description'], (name) => `lu:${name}`)).toEqual({ title: 'lu:title', description: 'lu:description' })
  })
})

describe('entryField', () => {
  it('lit la prose de la liste de proseFields.ts, le nom n\'étant écrit qu\'une fois', () => {
    const options = { control: 'text', copiedToNewVersion: false, column: false } as const

    expect(entryField('title', { ...options, proseFields: ['title'] }).prose).toBe(true)
    expect(entryField('iconKey', { ...options, proseFields: ['title'] }).prose).toBe(false)
  })
})

describe('les champs constants des deux sections', () => {
  it('principes : les noms, la prose de proseFields.ts, ce qui est recopié et la colonne', () => {
    expect(QUALITY_PRINCIPLE_FIELDS.map((field) => field.name)).toEqual(['title', 'description', 'iconKey'])
    expect(QUALITY_PRINCIPLE_FIELDS.filter((field) => field.prose).map((field) => field.name)).toEqual([...QUALITY_PRINCIPLE_PROSE_FIELDS])
    expect(QUALITY_PRINCIPLE_FIELDS.filter((field) => field.copiedToNewVersion).map((field) => field.name)).toEqual(['iconKey'])
    expect(QUALITY_PRINCIPLE_FIELDS.filter((field) => field.column).map((field) => field.name)).toEqual(['iconKey'])
    expect(QUALITY_PRINCIPLE_FIELDS.map((field) => field.control)).toEqual(['text', 'textarea', 'text'])
  })

  it('traits : un libellé, prose, rien de recopié, aucune colonne', () => {
    expect(QUALITY_TRAIT_FIELDS.map((field) => field.name)).toEqual(['label'])
    expect(QUALITY_TRAIT_FIELDS.filter((field) => field.prose).map((field) => field.name)).toEqual([...QUALITY_TRAIT_PROSE_FIELDS])
    expect(QUALITY_TRAIT_FIELDS.some((field) => field.copiedToNewVersion || field.column)).toBe(false)
  })
})
