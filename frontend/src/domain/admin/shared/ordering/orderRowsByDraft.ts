import type { TranslationGroupRow } from './groupByTranslationGroup'

/**
 * Réordonne les lignes d'un tableau groupé selon un brouillon d'ordre : c'est
 * le brouillon en cours que l'admin doit voir pendant son glisser-déposer,
 * l'ordre du serveur ne revenant qu'après « Annuler » ou un enregistrement.
 *
 * Une clé du brouillon sans ligne correspondante est **ignorée** plutôt que
 * rendue vide : entre le `move` et le rechargement qui suit un enregistrement,
 * les deux listes peuvent diverger d'un instant, et une ligne fantôme y serait
 * pire qu'une ligne absente. Le cas durable, lui, est traité par le serveur —
 * la règle d'ensemble exact (spec 0004, D4) refuse un ordre devenu obsolète.
 *
 * Extrait des pages admin, qui en avaient chacune leur copie (Incidents,
 * Contributions, CV sans identité, Qualité), avant que la page À propos n'en
 * demande quatre de plus à elle seule : un tableau pour les cartes « site »,
 * un par catégorie pour les cartes « moi ».
 */
export function orderRowsByDraft<T>(
  rows: readonly TranslationGroupRow<T>[],
  draft: readonly string[],
): TranslationGroupRow<T>[] {
  const byKey = new Map(rows.map((row) => [row.key, row]))

  return draft.map((key) => byKey.get(key)).filter((row): row is TranslationGroupRow<T> => undefined !== row)
}
