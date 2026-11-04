import { createI18n } from 'vue-i18n'
import { DEFAULT_LOCALE } from '../../domain/portfolio/entities/Locale'
import fr from '../../infrastructure/i18n/locales/fr.json'
import en from '../../infrastructure/i18n/locales/en.json'

/** Les messages que `createAppI18n` déclare : les deux langues, telles que les fichiers JSON les écrivent. */
const messages = { fr, en }

/**
 * Les clés de `admin.translation.errors` présentes dans **les deux** langues (une intersection de clés, non l'union) : un type
 * construit sur elle refuse une clé qui manque à l'une des deux, là où `typeof fr.…` seul ne voit pas `en.json`. Le type
 * vit ici, avec les messages qu'il décrit, pour que `presentation/ui` n'importe rien d'`infrastructure`.
 */
export type TranslationErrorTextKey = keyof (typeof messages)['fr']['admin']['translation']['errors'] &
  keyof (typeof messages)['en']['admin']['translation']['errors']

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
    messages,
  })
}

/** Instance réelle de l'application, utilisée par `main.ts` et par `presentation/router`. */
export const i18n = createAppI18n()
