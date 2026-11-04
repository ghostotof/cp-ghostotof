import { DEFAULT_LOCALE, isSupportedLocale, type Locale } from '../../domain/portfolio/entities/Locale'

export const LOCALE_STORAGE_KEY = 'locale'

/**
 * Résout la locale à utiliser pour un premier accès sans préfixe ("/") :
 * 1. le choix explicite précédent de l'utilisateur (localStorage), s'il existe et si le stockage se lit ;
 * 2. sinon l'anglais, uniquement si c'est la langue par défaut du client (`navigator.language`) ;
 * 3. le français dans tous les autres cas (y compris une langue tierce non supportée) —
 *    c'est la langue par défaut du site, l'anglais est la seule exception explicite.
 */
export function resolvePreferredLocale(): Locale {
  const stored = storedLocaleChoice()
  if (isSupportedLocale(stored)) {
    return stored
  }

  return isClientDefaultLanguageEnglish() ? 'en' : DEFAULT_LOCALE
}

/** Le choix retenu, ou `null` quand le stockage refuse la lecture : la redirection de `/` aboutit quand même. */
function storedLocaleChoice(): string | null {
  try {
    return localStorage.getItem(LOCALE_STORAGE_KEY)
  } catch {
    // Stockage indisponible (navigation privée, données de site bloquées) : la langue du navigateur décide.
    return null
  }
}

function isClientDefaultLanguageEnglish(): boolean {
  return navigator.language.slice(0, 2).toLowerCase() === 'en'
}
