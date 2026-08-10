import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { LOCALE_STORAGE_KEY, resolvePreferredLocale } from '../../../src/presentation/router/preferredLocale'

function setBrowserLanguage(language: string): void {
  vi.spyOn(navigator, 'language', 'get').mockReturnValue(language)
}

describe('resolvePreferredLocale', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('français par défaut quand le navigateur ne précise aucune préférence connue', () => {
    setBrowserLanguage('de-DE')

    expect(resolvePreferredLocale()).toBe('fr')
  })

  it('français par défaut même si le navigateur est explicitement en français', () => {
    setBrowserLanguage('fr-FR')

    expect(resolvePreferredLocale()).toBe('fr')
  })

  it('anglais uniquement si la langue par défaut du client est anglaise', () => {
    setBrowserLanguage('en-US')

    expect(resolvePreferredLocale()).toBe('en')
  })

  it('respecte un choix explicite précédent (localStorage), même si le navigateur est anglais', () => {
    setBrowserLanguage('en-US')
    localStorage.setItem(LOCALE_STORAGE_KEY, 'fr')

    expect(resolvePreferredLocale()).toBe('fr')
  })

  it('respecte un choix explicite précédent (localStorage) vers l\'anglais, même si le navigateur est en français', () => {
    setBrowserLanguage('fr-FR')
    localStorage.setItem(LOCALE_STORAGE_KEY, 'en')

    expect(resolvePreferredLocale()).toBe('en')
  })
})
