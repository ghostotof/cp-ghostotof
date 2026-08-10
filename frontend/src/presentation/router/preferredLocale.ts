import { DEFAULT_LOCALE, isSupportedLocale, type Locale } from '../../domain/portfolio/entities/Locale'

export const LOCALE_STORAGE_KEY = 'locale'

/**
 * Résout la locale à utiliser pour un premier accès sans préfixe ("/") :
 * 1. le choix explicite précédent de l'utilisateur (localStorage), s'il existe ;
 * 2. sinon l'anglais, uniquement si c'est la langue par défaut du client (`navigator.language`) ;
 * 3. le français dans tous les autres cas (y compris une langue tierce non supportée) —
 *    c'est la langue par défaut du site, l'anglais est la seule exception explicite.
 */
export function resolvePreferredLocale(): Locale {
  const stored = localStorage.getItem(LOCALE_STORAGE_KEY)
  if (isSupportedLocale(stored)) {
    return stored
  }

  return isClientDefaultLanguageEnglish() ? 'en' : DEFAULT_LOCALE
}

function isClientDefaultLanguageEnglish(): boolean {
  return navigator.language.slice(0, 2).toLowerCase() === 'en'
}
