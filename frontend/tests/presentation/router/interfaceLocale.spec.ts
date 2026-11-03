import { afterEach, describe, expect, it } from 'vitest'
import { applyInterfaceLocale } from '../../../src/presentation/router/interfaceLocale'
import { i18n } from '../../../src/presentation/i18n'
import { LOCALE_STORAGE_KEY } from '../../../src/presentation/router/preferredLocale'

describe('applyInterfaceLocale', () => {
  afterEach(() => {
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
})
