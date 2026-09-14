import type { Locale } from '../../../portfolio/entities/Locale'

/**
 * Une ligne du tableau ordonné du backoffice (spec 0004, D8) : le contenu
 * d'un même `translationGroup`, une entrée par langue de `byLocale`. Pure
 * projection de lecture — aucune des briques `ordering/` ne connaît la forme
 * exacte de `T`, seulement les trois champs dont elle a besoin pour grouper.
 */
export interface TranslationGroupRow<T> {
  readonly key: string
  readonly position: number
  readonly byLocale: Partial<Record<Locale, T>>
  readonly missing: Locale[]
}

/**
 * Regroupe des entrées localisées par `translationGroup` : une ligne par
 * groupe, triée par position puis, à position égale, par ordre de première
 * apparition dans `entries` (le tri de `Array.prototype.sort` est stable
 * depuis ES2019, donc préserver cet ordre initial suffit à départager).
 *
 * `missing` liste les locales de `locales` qu'aucune entrée du groupe ne
 * porte — c'est ce qui permet au tableau d'afficher « Traduction manquante »
 * et un bouton « Créer la version XX » (D8) sans connaître la structure de
 * `T` par ailleurs.
 */
export function groupByTranslationGroup<T extends { translationGroup: string; locale: string; position: number }>(
  entries: readonly T[],
  locales: readonly Locale[],
): TranslationGroupRow<T>[] {
  const appearanceOrder: string[] = []
  const membersByGroup = new Map<string, T[]>()

  for (const entryItem of entries) {
    const members = membersByGroup.get(entryItem.translationGroup)
    if (members) {
      members.push(entryItem)
    } else {
      membersByGroup.set(entryItem.translationGroup, [entryItem])
      appearanceOrder.push(entryItem.translationGroup)
    }
  }

  const rows = appearanceOrder.map((key): TranslationGroupRow<T> => {
    const members = membersByGroup.get(key) ?? []
    const byLocale: Partial<Record<Locale, T>> = {}

    for (const member of members) {
      if ((locales as readonly string[]).includes(member.locale)) {
        byLocale[member.locale as Locale] = member
      }
    }

    const missing = locales.filter((locale) => undefined === byLocale[locale])
    const position = Math.min(...members.map((member) => member.position))

    return { key, position, byLocale, missing }
  })

  return rows.sort((a, b) => a.position - b.position)
}
