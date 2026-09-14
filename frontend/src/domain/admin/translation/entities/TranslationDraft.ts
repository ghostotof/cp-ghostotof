import type { Locale } from '../../../portfolio/entities/Locale'

/** Dictionnaire `nom de champ → texte`, tel qu'envoyé et reçu par l'assistant. */
export type TranslationFields = Readonly<Record<string, string>>

/**
 * Ce que l'assistant renvoie : le même dictionnaire, dans la locale cible.
 * Un brouillon, jamais une entrée — il n'entre en base que si l'humain
 * l'enregistre par le formulaire habituel (ADR 0004, D4).
 */
export interface TranslationDraft {
  readonly sourceLocale: Locale
  readonly targetLocale: Locale
  readonly fields: TranslationFields
}
