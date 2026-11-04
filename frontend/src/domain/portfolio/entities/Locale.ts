export const SUPPORTED_LOCALES = ['fr', 'en'] as const

export type Locale = (typeof SUPPORTED_LOCALES)[number]

export const DEFAULT_LOCALE: Locale = 'fr'

/**
 * L'autre des langues supportées (la cible d'une traduction depuis `locale`) ; la première par défaut si `locale`
 * n'en fait pas partie. Une seule règle pour le bouton de traduction et pour les formulaires qui le désactivent.
 */
export function otherLocale(locale: Locale): Locale {
  return SUPPORTED_LOCALES.find((candidate) => candidate !== locale) ?? SUPPORTED_LOCALES[0]
}

export function isSupportedLocale(value: string | null | undefined): value is Locale {
  return !!value && (SUPPORTED_LOCALES as readonly string[]).includes(value)
}

/**
 * Nom natif (autonyme) de chaque langue : toujours affiché dans sa propre langue
 * (convention des sélecteurs de langue), jamais traduit selon la locale active.
 */
export const LOCALE_NATIVE_NAMES: Record<Locale, string> = {
  fr: 'Français',
  en: 'English',
}
