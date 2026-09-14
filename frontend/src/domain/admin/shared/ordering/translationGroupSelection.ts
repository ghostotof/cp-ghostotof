import type { Locale } from '../../../portfolio/entities/Locale'
import type { TranslationGroupRow } from './groupByTranslationGroup'

/**
 * Fonctions pures partagées par toute page de backoffice construite sur le
 * tableau groupé (spec 0004, D8) : sélection des options « Version de »,
 * lignes internes par langue, entrée représentative d'un groupe et détection
 * d'une sœur de traduction. Extraites d'`AdminIncidentsPage.vue` (B6), qui les
 * inlinait — chaque nouvelle page les aurait sinon recopiées à l'identique.
 *
 * Aucune de ces fonctions ne connaît la forme exacte de `T` au-delà des
 * champs dont elle a besoin (`id`, `locale`, `translationGroup`) : elles
 * restent utilisables par des entités qui ne portent ni les mêmes champs de
 * prose ni le même nom d'affichage.
 */

export interface TranslationGroupOption {
  readonly value: string
  readonly label: string
}

/**
 * Options du sélecteur « Version de » (D2) : les entrées de **toute autre
 * locale** dont le groupe n'a pas encore `formLocale`. L'entrée en cours
 * d'édition (`editingId`) ne compte pas comme occupant sa propre locale, sans
 * quoi son propre groupe disparaîtrait des options et le formulaire ne
 * pourrait plus le renvoyer.
 *
 * Le groupe déjà retenu (`formTranslationGroup`) est toujours conservé : le
 * sélecteur ne doit jamais afficher une valeur absente de ses options — un
 * rattachement devenu impossible se solde par un 409 explicite, pas par un
 * champ vide.
 *
 * Ne porte pas l'option « aucune » : c'est à la page de la préfixer, son
 * libellé étant un texte traduit (i18n), donc hors de ce module
 * framework-free. `labelOf` fournit le libellé affiché (souvent
 * `${locale} · ${titre}`), différent selon le champ qui nomme l'entrée d'une
 * page à l'autre.
 */
export function translationGroupOptions<T extends { id: string; locale: string; translationGroup: string }>(
  entries: readonly T[],
  formLocale: string,
  formTranslationGroup: string,
  editingId: string | null,
  labelOf: (entry: T) => string,
): TranslationGroupOption[] {
  const options: TranslationGroupOption[] = []
  const seen = new Set<string>()

  for (const entry of entries) {
    if (entry.locale === formLocale || seen.has(entry.translationGroup)) {
      continue
    }

    const alreadyTranslated = entries.some(
      (other) =>
        other.locale === formLocale && other.translationGroup === entry.translationGroup && other.id !== editingId,
    )

    if (alreadyTranslated && entry.translationGroup !== formTranslationGroup) {
      continue
    }

    seen.add(entry.translationGroup)
    options.push({ value: entry.translationGroup, label: labelOf(entry) })
  }

  return options
}

export interface RowLine<T> {
  readonly locale: Locale
  readonly nativeName: string
  readonly entry: T | null
}

/**
 * Une ligne interne par langue de `locales` (D2 : jamais 'fr'/'en' en dur côté
 * appelant), `entry` à `null` quand la traduction manque — c'est ce qui
 * permet au tableau d'afficher « Traduction manquante » et le bouton
 * « Créer la version XX ».
 */
export function rowLines<T>(
  row: TranslationGroupRow<T>,
  locales: readonly Locale[],
  nativeNames: Readonly<Record<Locale, string>>,
): RowLine<T>[] {
  return locales.map((locale) => ({
    locale,
    nativeName: nativeNames[locale],
    entry: row.byLocale[locale] ?? null,
  }))
}

/** Première entrée disponible du groupe : ce qui nomme et date la ligne quand une langue manque. */
export function firstEntry<T>(row: TranslationGroupRow<T>, locales: readonly Locale[]): T | null {
  for (const locale of locales) {
    const entry = row.byLocale[locale]
    if (entry) {
      return entry
    }
  }

  return null
}

/** Vrai si `entry` a une autre entrée dans son groupe — une sœur dans une autre langue. */
export function hasSibling<T extends { id: string; translationGroup: string }>(
  entries: readonly T[],
  entry: T,
): boolean {
  return entries.some((other) => other.translationGroup === entry.translationGroup && other.id !== entry.id)
}
