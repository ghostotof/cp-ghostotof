import type { Locale } from '../../../portfolio/entities/Locale'
import type { TranslationDraft, TranslationFields } from '../entities/TranslationDraft'

/**
 * Abstraction (DIP) de l'assistant de traduction du backoffice (spec 0002).
 * Agnostique du contenu : la page appelante choisit les champs de prose et
 * recopie le reste.
 */
export interface AdminTranslationRepository {
  translate(sourceLocale: Locale, targetLocale: Locale, fields: TranslationFields): Promise<TranslationDraft>
}
