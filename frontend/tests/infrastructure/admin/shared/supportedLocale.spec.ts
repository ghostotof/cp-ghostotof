import { describe, expect, it } from 'vitest'
import { supportedLocaleOf, UNSUPPORTED_LOCALE_MESSAGE } from '../../../../src/infrastructure/admin/shared/supportedLocale'

/** L'erreur que le dépôt aurait levée : le test la reconnaît à sa classe pour savoir laquelle a été rendue. */
class UnsupportedLocaleStub extends Error {
  constructor() {
    super('langue refusée')
  }
}

const unsupported = (): Error => new UnsupportedLocaleStub()

describe('supportedLocaleOf', () => {
  it("rend 'fr' et 'en' tels quels (#748, R214)", () => {
    expect(supportedLocaleOf('fr', unsupported)).toBe('fr')
    expect(supportedLocaleOf('en', unsupported)).toBe('en')
  })

  it("lève l'erreur que rend `unsupported` pour une langue que le site ne porte pas (#748, R214)", () => {
    expect(() => supportedLocaleOf('de', unsupported)).toThrow(UnsupportedLocaleStub)
    expect(() => supportedLocaleOf('de', unsupported)).toThrow('langue refusée')
  })

  it("refuse la chaîne vide et une casse différente : 'FR' n'est pas 'fr' (#748, R214)", () => {
    expect(() => supportedLocaleOf('', unsupported)).toThrow(UnsupportedLocaleStub)
    expect(() => supportedLocaleOf('FR', unsupported)).toThrow(UnsupportedLocaleStub)
  })
})

describe('UNSUPPORTED_LOCALE_MESSAGE', () => {
  // Le message que chaque dépôt du backoffice porte dans son erreur `unknown` : écrit une fois, ici (#748, R229 de la dix-huitième
  // relecture, St18-8).
  it('dit la rupture du contrat, en anglais, sans citer la valeur reçue du serveur (#748, R229)', () => {
    expect(UNSUPPORTED_LOCALE_MESSAGE).toBe('Unsupported locale in response')
  })
})
