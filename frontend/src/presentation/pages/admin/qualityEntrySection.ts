import type { Ref } from 'vue'
import type { Locale } from '../../../domain/portfolio/entities/Locale'
import type { AdminQualityError } from '../../../domain/admin/quality/errors/AdminQualityError'
import { QUALITY_PRINCIPLE_PROSE_FIELDS, QUALITY_TRAIT_PROSE_FIELDS } from './proseFields'

/**
 * Ce que `AdminQualityEntrySection` sait de ses deux instances, les principes et les traits de qualité (#748, R190) : la
 * forme commune de leurs entrées, de leurs écritures, de la lecture que la page lui passe, et la liste des champs qui fait
 * toute la différence entre l'une et l'autre. Le gabarit n'a aucune branche sur le genre d'entrée : chaque différence est une
 * propriété d'un champ.
 *
 * `F` est l'union des noms de champs d'une instance (`'title' | 'description' | 'iconKey'`, ou `'label'`) : le compilateur
 * vérifie ainsi que l'entrée porte chacun de ses champs, que l'écriture les envoie tous, et que la page traduit chacun.
 */

/** Une entrée lue : ce que partagent les deux contenus (clé, langue, groupe de traduction, position), plus ses champs. */
export type QualityEntry<F extends string> = {
  readonly id: string
  readonly locale: Locale
  readonly translationGroup: string
  readonly position: number
} & { readonly [K in F]: string }

/** Le corps d'écriture : `translationGroup` à `null` crée un contenu neuf ou, sur une mise à jour, détache (spec 0004, D1, D3). */
export type QualityEntryInput<F extends string> = {
  locale: Locale
  translationGroup: string | null
} & { [K in F]: string }

/**
 * Un champ du formulaire d'une section. Chaque propriété remplace ce qu'une branche sur le genre d'entrée aurait écrit :
 *
 * - `control` : le champ de saisie, une ligne (`text`) ou un paragraphe (`textarea`) ;
 * - `prose` : le champ est traduit par l'assistant, donc en lecture seule pendant une traduction (`proseFields.ts`, spec 0004
 *   D2 : chaque formulaire décide des siens) ;
 * - `copiedToNewVersion` : « Créer la version XX » le recopie de l'entrée existante, la prose restant vide faute de texte à
 *   traduire (une clé d'icône n'a pas de traduction) ;
 * - `column` : le tableau lui consacre une colonne, après la cellule de contenu.
 */
export interface QualityEntryFieldShape<F extends string> {
  readonly name: F
  readonly control: 'text' | 'textarea'
  readonly prose: boolean
  readonly copiedToNewVersion: boolean
  readonly column: boolean
}

/** Un champ et son libellé, traduit par la page (clé littérale, que `no-missing-keys` vérifie). */
export interface QualityEntryField<F extends string> extends QualityEntryFieldShape<F> {
  readonly label: string
}

/**
 * Les textes propres à une instance, traduits par la page avec des clés littérales : `no-missing-keys` les vérifie, ce qu'une
 * clé construite (`admin.quality.${kind}.…`) ne permettrait plus. Les textes que les deux instances partagent
 * (`admin.quality.save`, `admin.quality.cancel`…) sont lus par la section elle-même.
 */
export interface QualityEntryTexts {
  /** Titre du formulaire en création. */
  readonly create: string
  /** Titre du formulaire quand une entrée est éditée. */
  readonly edit: string
  /** Titre du tableau. */
  readonly listTitle: string
  /** Bouton « Modifier » de chaque ligne de langue. */
  readonly editAction: string
  /** État vide du tableau, dit seulement d'une liste lue. */
  readonly empty: string
  /** La question de la suppression, qui nomme l'entrée par son champ titre. */
  readonly confirmDelete: (entryTitle: string) => string
}

/**
 * Ce que la page passe à la section, de la lecture du composable (`useAdminQualityPrinciples`, `useAdminQualityTraits`) : la
 * liste sous le nom `entries`, l'état de chargement et les écritures. La section le lit une fois, à sa construction : un
 * composable ne change pas de main en cours de vie.
 */
export interface QualityEntrySource<E, I> {
  readonly entries: Readonly<Ref<readonly E[]>>
  readonly isLoading: Readonly<Ref<boolean>>
  readonly hasError: Readonly<Ref<boolean>>
  readonly isLoaded: Readonly<Ref<boolean>>
  readonly errorMessage: Readonly<Ref<AdminQualityError | null>>
  readonly load: () => Promise<void>
  readonly create: (input: I) => Promise<boolean>
  readonly update: (id: string, input: I) => Promise<boolean>
  readonly remove: (id: string) => Promise<boolean>
  readonly reorder: (keys: readonly string[]) => Promise<void>
}

/** Vrai si `name` est dans la liste des champs de prose de l'instance (`proseFields.ts`, la source de ces listes). */
function isProse(proseFields: readonly string[], name: string): boolean {
  return proseFields.includes(name)
}

/** Principes : un titre et une description, traduits, et une clé d'icône, recopiée et affichée en colonne. */
export const QUALITY_PRINCIPLE_FIELDS: readonly QualityEntryFieldShape<'title' | 'description' | 'iconKey'>[] = [
  { name: 'title', control: 'text', prose: isProse(QUALITY_PRINCIPLE_PROSE_FIELDS, 'title'), copiedToNewVersion: false, column: false },
  {
    name: 'description',
    control: 'textarea',
    prose: isProse(QUALITY_PRINCIPLE_PROSE_FIELDS, 'description'),
    copiedToNewVersion: false,
    column: false,
  },
  { name: 'iconKey', control: 'text', prose: isProse(QUALITY_PRINCIPLE_PROSE_FIELDS, 'iconKey'), copiedToNewVersion: true, column: true },
]

/** Traits : un libellé, seul champ et prose, rien à recopier dans « Créer la version XX », aucune colonne. */
export const QUALITY_TRAIT_FIELDS: readonly QualityEntryFieldShape<'label'>[] = [
  { name: 'label', control: 'text', prose: isProse(QUALITY_TRAIT_PROSE_FIELDS, 'label'), copiedToNewVersion: false, column: false },
]

/**
 * Joint à chaque champ son libellé traduit. `labels` est un enregistrement indexé par `F` : le compilateur refuse la liste à
 * laquelle il manque un libellé, ou qui en a un de trop.
 */
export function withLabels<F extends string>(
  shapes: readonly QualityEntryFieldShape<F>[],
  labels: Readonly<Record<F, string>>,
): readonly QualityEntryField<F>[] {
  return shapes.map((shape) => ({ ...shape, label: labels[shape.name] }))
}
