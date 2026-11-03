import type { Locale } from '../../domain/portfolio/entities/Locale'
import { i18n } from '../i18n'
import { LOCALE_STORAGE_KEY } from './preferredLocale'

/**
 * Pose la langue d'interface de l'application et ses deux reflets (#748, R227) : vue-i18n, le choix retenu pour le
 * prochain accès à `/` (`localStorage`, lu par `resolvePreferredLocale`) et `document.documentElement.lang` (la langue
 * que lit un lecteur d'écran).
 *
 * Deux appelants : le routeur, à chaque navigation, depuis la route (ou le premier segment de la page 404), et le
 * sélecteur de langue sur une page sans préfixe (`/frogs`), où il n'y a aucune adresse à changer (D4).
 *
 * L'écriture `i18n.global.locale.value = …` est la pose que la garde de source distingue d'une lecture.
 */
export function applyInterfaceLocale(locale: Locale): void {
  i18n.global.locale.value = locale
  localStorage.setItem(LOCALE_STORAGE_KEY, locale)
  document.documentElement.lang = locale
}
