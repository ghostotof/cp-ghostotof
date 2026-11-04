import { describe, expect, it } from 'vitest'
import { proseFieldId } from './proseFieldId'

describe('proseFieldId', () => {
  it("écrit l'identifiant d'un champ en kebab, derrière le préfixe de son formulaire (#748, R138)", () => {
    expect(proseFieldId('admin-incident', 'rootCause')).toBe('admin-incident-root-cause')
    expect(proseFieldId('admin-about', 'siteEyebrow')).toBe('admin-about-site-eyebrow')
    expect(proseFieldId('admin-case-study', 'title')).toBe('admin-case-study-title')
    expect(proseFieldId('admin-case-study', 'measuredResult')).toBe('admin-case-study-measured-result')
  })
})
