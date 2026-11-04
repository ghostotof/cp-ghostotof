import { afterEach, describe, expect, it, vi } from 'vitest'
import { applyInterfaceLocale } from '../../../src/presentation/router/interfaceLocale'
import { i18n } from '../../../src/presentation/i18n'
import { LOCALE_STORAGE_KEY } from '../../../src/presentation/router/preferredLocale'

describe('applyInterfaceLocale', () => {
  afterEach(() => {
    vi.restoreAllMocks()
    i18n.global.locale.value = 'fr'
    localStorage.removeItem(LOCALE_STORAGE_KEY)
    document.documentElement.lang = ''
  })

  it('pose la langue de l’application, le choix retenu et la langue du document (#748, R227)', () => {
    applyInterfaceLocale('en')

    expect(i18n.global.locale.value).toBe('en')
    expect(localStorage.getItem(LOCALE_STORAGE_KEY)).toBe('en')
    expect(document.documentElement.lang).toBe('en')
  })

  // CR19-3 : le routeur l'appelle dans `beforeEach` ; un stockage qui lève y ferait avorter chaque navigation.
  it('sous un stockage bloqué, pose quand même la langue de l’application et celle du document', () => {
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new DOMException('Quota dépassé', 'QuotaExceededError')
    })

    expect(() => applyInterfaceLocale('en')).not.toThrow()
    expect(i18n.global.locale.value).toBe('en')
    expect(document.documentElement.lang).toBe('en')
  })
})
