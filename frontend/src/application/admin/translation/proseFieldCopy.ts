import type { TranslationProposal, TranslationFields } from '../../../domain/admin/translation/entities/TranslationProposal'

/**
 * Les deux gestes que tout formulaire admin fait autour de l'assistant de
 * traduction : extraire de son formulaire les champs de prose à envoyer, puis
 * y reporter la proposition reçue. Écrits une fois — `useTranslatedForm`
 * (`proposeFrom`) et les réglages « À propos » les appellent — : une correction
 * (un champ blanc envoyé, un champ écrasé par erreur) s'applique partout d'un coup.
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

/** Remplace la prose par la proposition ; un champ absent de la proposition est laissé tel quel. */
export function applyTranslationProposal<K extends string>(form: Record<K, string>, keys: readonly K[], proposal: TranslationProposal): void {
  for (const key of keys) {
    const translated = proposal.fields[key]
    if (undefined !== translated) {
      form[key] = translated
    }
  }
}
