import { describe, expect, it } from 'vitest'
import { supportedLocaleOf } from '../../../../src/infrastructure/admin/shared/supportedLocale'

/** L'erreur que le dépôt aurait levée : le test lit ce qu'elle porte pour savoir laquelle a été rendue. */
class UnsupportedLocaleStub extends Error {
  readonly value: string

  constructor(value: string) {
    super('langue refusée')
    this.value = value
  }
}

describe('supportedLocaleOf', () => {
  it("rend 'fr' et 'en' tels quels (#748, R214)", () => {
    const unsupported = (value: string): Error => new UnsupportedLocaleStub(value)

    expect(supportedLocaleOf('fr', unsupported)).toBe('fr')
    expect(supportedLocaleOf('en', unsupported)).toBe('en')
  })

  it("lève l'erreur que rend `unsupported`, avec la valeur reçue, pour une langue que le site ne porte pas (#748, R214)", () => {
    const unsupported = (value: string): Error => new UnsupportedLocaleStub(value)

    expect(() => supportedLocaleOf('de', unsupported)).toThrow(UnsupportedLocaleStub)
    expect(() => supportedLocaleOf('de', unsupported)).toThrow('langue refusée')

    const caught = (() => {
      try {
        supportedLocaleOf('de', unsupported)
      } catch (error) {
        return error
      }
      return null
    })()
    expect(caught).toBeInstanceOf(UnsupportedLocaleStub)
    expect((caught as UnsupportedLocaleStub).value).toBe('de')
  })

  it("refuse la chaîne vide et une casse différente : 'FR' n'est pas 'fr' (#748, R214)", () => {
    const unsupported = (value: string): Error => new UnsupportedLocaleStub(value)

    expect(() => supportedLocaleOf('', unsupported)).toThrow(UnsupportedLocaleStub)
    expect(() => supportedLocaleOf('FR', unsupported)).toThrow(UnsupportedLocaleStub)
  })
})
