import { createI18n } from 'vue-i18n'
import { DEFAULT_LOCALE } from '../../domain/portfolio/entities/Locale'
import fr from '../../infrastructure/i18n/locales/fr.json'
import en from '../../infrastructure/i18n/locales/en.json'

/**
 * Factory (plutôt qu'un singleton exporté directement) pour que chaque test puisse
 * monter ses composants avec sa propre instance i18n isolée, sans état partagé entre
 * fichiers de test — même raison que `presentation/router` construit un routeur frais
 * par test au lieu de réutiliser un singleton partagé.
 */
export function createAppI18n() {
  return createI18n({
    legacy: false,
    locale: DEFAULT_LOCALE,
    fallbackLocale: DEFAULT_LOCALE,
    messages: { fr, en },
  })
}

/** Instance réelle de l'application, utilisée par `main.ts` et par `presentation/router`. */
export const i18n = createAppI18n()
