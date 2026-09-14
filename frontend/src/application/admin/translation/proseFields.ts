import type { TranslationDraft, TranslationFields } from '../../../domain/admin/translation/entities/TranslationDraft'

/**
 * Les deux gestes que toute page admin répète autour de l'assistant de
 * traduction : extraire de son formulaire les champs de prose à envoyer, puis
 * y reporter le brouillon reçu. Partagés plutôt que recopiés page par page —
 * une correction (un champ blanc envoyé, un champ écrasé par erreur)
 * s'applique alors partout d'un coup.
 *
 * La page reste seule à décider *quels* champs sont de la prose (spec 0002,
 * D2) et ce qu'elle fait du formulaire ensuite (bascule en création, bannière).
 */

/** Ne retient que les champs non vides : l'API refuse une valeur blanche (422). */
export function collectProseFields<K extends string>(form: Record<K, string>, keys: readonly K[]): TranslationFields {
  const fields: Record<string, string> = {}
  for (const key of keys) {
    if ('' !== form[key].trim()) {
      fields[key] = form[key]
    }
  }

  return fields
}

/** Remplace la prose par le brouillon ; un champ absent du brouillon est laissé tel quel. */
export function applyTranslationDraft<K extends string>(form: Record<K, string>, keys: readonly K[], draft: TranslationDraft): void {
  for (const key of keys) {
    const translated = draft.fields[key]
    if (undefined !== translated) {
      form[key] = translated
    }
  }
}
