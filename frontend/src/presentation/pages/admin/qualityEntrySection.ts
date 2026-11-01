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
 * `F` est l'union des noms de champs d'une instance (`'title' | 'description' | 'iconKey'`, ou `'label'`). Ce que le compilateur
 * vérifie : que l'entrée (`QualityEntry<F>`) et l'écriture (`QualityEntryInput<F>`) portent un champ par nom de `F`, que la page
 * donne un libellé à chacun (`labels: Record<F, string>`) et que le formulaire (`EntryForm<F>`) en a un par nom. Ce qu'il ne
 * vérifie pas : qu'une **liste** de champs (`readonly QualityEntryField<F>[]`) les nomme tous, un tableau ne le disant pas ;
 * le formulaire et le corps d'écriture se construisent de cette liste à l'exécution, et `hasEveryField` (par
 * `requireEveryField`, `fieldsFrom`) le contrôle là : un nom de `F` absent de la liste lève `MissingEntryFieldError`, au lieu
 * d'un champ `undefined` envoyé.
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
 * Le formulaire d'une section : la langue et le groupe de traduction choisis, plus une chaîne par nom de `F` (une saisie, vide
 * tant que rien n'est écrit). `locale` est typée `Locale` et non laissée à l'inférence de `reactive`, qui la réduirait au
 * littéral de sa valeur initiale.
 */
export type EntryForm<F extends string> = { locale: Locale; translationGroup: string } & Record<F, string>

/** Un nom de `F` que la liste des champs ne porte pas : une faute de programmation, que le compilateur ne voit pas (voir en-tête). */
export class MissingEntryFieldError extends Error {
  readonly fieldName: string

  constructor(fieldName: string) {
    super(`Le champ « ${fieldName} » manque à la liste des champs de la section.`)
    this.name = 'MissingEntryFieldError'
    this.fieldName = fieldName
  }
}

/**
 * Vrai si `value` porte une chaîne pour chacun des `names` : la garde qui fait d'un objet bâti à l'exécution
 * (`Object.fromEntries`, indexé par une chaîne quelconque) un `Record<F, string>` que le compilateur accepte, sans assertion.
 */
export function hasEveryField<F extends string>(
  value: Readonly<Record<string, string | undefined>>,
  names: readonly F[],
): value is Record<F, string> {
  return names.every((name) => 'string' === typeof value[name])
}

/**
 * Rend `value` lui-même (le même objet : un objet réactif reste le sien), typé comme portant un champ par nom de `names`. Lève
 * `MissingEntryFieldError`, nommant le premier absent, si un nom manque : c'est la garde d'exhaustivité que le compilateur ne
 * peut pas être, une liste de noms lue à l'exécution ne disant pas qu'elle est complète.
 */
export function requireEveryField<F extends string, V extends Readonly<Record<string, string | undefined>>>(
  value: V,
  names: readonly F[],
): V & Record<F, string> {
  if (!hasEveryField(value, names)) {
    throw new MissingEntryFieldError(names.find((name) => 'string' !== typeof value[name]) ?? '')
  }

  return value
}

/**
 * Un champ par nom de `names`, sa valeur lue par `valueOf` : le formulaire à blanc (`() => ''`), le corps d'écriture
 * (`(name) => form[name]`). Lève `MissingEntryFieldError` si la construction ne donne pas un champ par nom
 * (`requireEveryField`).
 */
export function fieldsFrom<F extends string>(names: readonly F[], valueOf: (name: F) => string): Record<F, string> {
  return requireEveryField(Object.fromEntries(names.map((name) => [name, valueOf(name)])), names)
}

/**
 * Un champ du formulaire d'une section, **sans son libellé** : la liste des champs est une constante, les libellés traduits
 * passent à part (`labels`, `Record<F, string>`, un `computed` de la page : ils suivent la langue de l'interface). Chaque
 * propriété remplace ce qu'une branche sur le genre d'entrée aurait écrit :
 *
 * - `control` : le champ de saisie, une ligne (`text`) ou un paragraphe (`textarea`) ;
 * - `prose` : le champ est traduit par l'assistant, donc en lecture seule pendant une traduction (`proseFields.ts`, spec 0004
 *   D2 : chaque formulaire décide des siens) ;
 * - `copiedToNewVersion` : « Créer la version XX » le recopie de l'entrée existante, la prose restant vide faute de texte à
 *   traduire (une clé d'icône n'a pas de traduction) ;
 * - `column` : le tableau lui consacre une colonne, après la cellule de contenu.
 */
export interface QualityEntryField<F extends string> {
  readonly name: F
  readonly control: 'text' | 'textarea'
  readonly prose: boolean
  readonly copiedToNewVersion: boolean
  readonly column: boolean
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

/** Ce que la fabrique `entryField` lit, hors le nom : la prose est celle de la liste de `proseFields.ts`, jamais redite. */
export interface EntryFieldOptions<F extends string> {
  readonly control: 'text' | 'textarea'
  /** La liste des champs de prose de l'instance (`proseFields.ts`, la source) : `prose` est « le nom y figure ». */
  readonly proseFields: readonly F[]
  readonly copiedToNewVersion: boolean
  readonly column: boolean
}

/** Un champ de la constante d'une section : son nom n'est écrit qu'ici, la prose se lit de la liste de `proseFields.ts`. */
export function entryField<F extends string>(name: F, options: EntryFieldOptions<F>): QualityEntryField<F> {
  const { proseFields, ...rest } = options

  return { name, ...rest, prose: proseFields.includes(name) }
}

/** Principes : un titre et une description, traduits, et une clé d'icône, recopiée et affichée en colonne. */
export const QUALITY_PRINCIPLE_FIELDS: readonly QualityEntryField<'title' | 'description' | 'iconKey'>[] = [
  entryField('title', { control: 'text', proseFields: QUALITY_PRINCIPLE_PROSE_FIELDS, copiedToNewVersion: false, column: false }),
  entryField('description', { control: 'textarea', proseFields: QUALITY_PRINCIPLE_PROSE_FIELDS, copiedToNewVersion: false, column: false }),
  entryField('iconKey', { control: 'text', proseFields: QUALITY_PRINCIPLE_PROSE_FIELDS, copiedToNewVersion: true, column: true }),
]

/** Traits : un libellé, seul champ et prose, rien à recopier dans « Créer la version XX », aucune colonne. */
export const QUALITY_TRAIT_FIELDS: readonly QualityEntryField<'label'>[] = [
  entryField('label', { control: 'text', proseFields: QUALITY_TRAIT_PROSE_FIELDS, copiedToNewVersion: false, column: false }),
]
