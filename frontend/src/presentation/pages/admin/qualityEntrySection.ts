import type { Ref } from 'vue'
import type { Locale } from '../../../domain/portfolio/entities/Locale'
import type { AdminQualityError } from '../../../domain/admin/quality/errors/AdminQualityError'

/**
 * Ce que `AdminQualityEntrySection` sait de ses deux instances, les principes et les traits de qualité (#748, R190 ; règle
 * `frontend-backoffice.md`, « Existing resources », `quality`) : la forme commune de leurs entrées, de leurs écritures, de la
 * lecture que la page lui passe, et les champs qui font toute la différence entre l'une et l'autre. Le gabarit n'a aucune
 * branche sur le genre d'entrée : chaque différence est une propriété d'un champ.
 *
 * `F` est l'union des noms de champs d'une instance (`'title' | 'description' | 'iconKey'`, ou `'label'`). Chaque instance
 * déclare ses champs par un **record** (`QUALITY_PRINCIPLE_FIELD_OPTIONS`, `as const satisfies Record<F, QualityFieldOptions>`),
 * dont les clés sont la liste des champs (`entryFieldsOf`) et dont `fieldRecord` construit le formulaire à blanc et le corps
 * d'écriture : il n'y a pas de liste à tenir à jour à côté du type, et pas de garde à l'exécution (R215, D1 de la dix-septième
 * relecture). Ce que le compilateur vérifie : que le record déclare un champ par nom de `F` (`satisfies`, un nom oublié ou en trop
 * est une erreur de compilation), que l'entrée (`QualityEntry<F>`) et l'écriture (`QualityEntryInput<F>`) portent un champ par nom
 * de `F`, et que la page donne un libellé à chacun (`labels: Record<F, string>`). Ce qu'il ne vérifie pas : le formulaire est un
 * `EntryForm<string>` (écart accepté de R202 : `reactive` d'un type indexé par le `F` générique épuise la mémoire de `vue-tsc`),
 * si bien que `form.nimporteQuoi` compile.
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

/**
 * Un champ par clé de `options`, sa valeur lue par `valueOf` : le formulaire à blanc (`() => ''`), le corps d'écriture
 * (`(name) => form[name]`). Seules les clés de `options` sont lues, jamais ses valeurs.
 *
 * C'est **la seule assertion de ce fichier et de la section** (`as Record<F, string>`) : un objet bâti à l'exécution est indexé
 * par une chaîne quelconque, et TypeScript n'accepte pas un `{ [clé: string]: string }` comme `Record<F, string>`. Elle est sûre
 * parce que les clés de `options` sont `F` (`options: Record<F, …>`, exhaustif par le `satisfies` de chaque instance) et que la
 * fonction ne fait que les recopier, une valeur chacune.
 */
export function fieldRecord<F extends string>(
  options: Readonly<Record<F, unknown>>,
  valueOf: (name: F) => string,
): Record<F, string> {
  const record: Partial<Record<F, string>> = {}

  for (const name in options) {
    record[name] = valueOf(name)
  }

  return record as Record<F, string>
}

/**
 * Un champ du formulaire d'une section, **sans son libellé** : la liste des champs est dérivée d'une constante
 * (`entryFieldsOf`), les libellés traduits passent à part (`labels`, `Record<F, string>`, un `computed` de la page : ils suivent
 * la langue de l'interface). Chaque propriété remplace ce qu'une branche sur le genre d'entrée aurait écrit :
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
export interface EntryFieldOptions {
  readonly control: 'text' | 'textarea'
  /** La liste des champs de prose de l'instance (`proseFields.ts`, la source) : `prose` est « le nom y figure ». */
  readonly proseFields: readonly string[]
  readonly copiedToNewVersion: boolean
  readonly column: boolean
}

/** Un champ de la liste d'une section (`entryFieldsOf` l'appelle) : sa prose se lit de la liste de `proseFields.ts`, jamais redite. */
export function entryField<F extends string>(name: F, options: EntryFieldOptions): QualityEntryField<F> {
  const { proseFields, ...rest } = options

  return { name, ...rest, prose: proseFields.includes(name) }
}

/**
 * Ce qu'un champ déclare de lui-même dans le record d'une instance : seul ce qui diffère du défaut s'écrit. Le défaut est
 * « rien n'est recopié dans "Créer la version XX" et le tableau ne lui consacre aucune colonne » ; `control` n'a pas de défaut.
 */
export interface QualityFieldOptions {
  readonly control: 'text' | 'textarea'
  /** Recopié de l'entrée existante par « Créer la version XX » (un champ sans traduction : une clé d'icône). */
  readonly copiedToNewVersion?: true
  /** Le tableau lui consacre une colonne. */
  readonly column?: true
}

/**
 * La liste des champs d'une instance, dans l'ordre des clés de son record, chaque défaut posé ici. La prose est celle de
 * `proseFields` (`proseFields.ts`), jamais redite dans le record.
 */
export function entryFieldsOf<F extends string>(
  options: Readonly<Record<F, QualityFieldOptions>>,
  proseFields: readonly string[],
): readonly QualityEntryField<F>[] {
  const fields: QualityEntryField<F>[] = []

  for (const name in options) {
    const { control, copiedToNewVersion = false, column = false } = options[name]

    fields.push(entryField(name, { control, proseFields, copiedToNewVersion, column }))
  }

  return fields
}

/** Principes : un titre et une description, traduits, et une clé d'icône, recopiée et affichée en colonne. */
export const QUALITY_PRINCIPLE_FIELD_OPTIONS = {
  title: { control: 'text' },
  description: { control: 'textarea' },
  iconKey: { control: 'text', copiedToNewVersion: true, column: true },
} as const satisfies Record<'title' | 'description' | 'iconKey', QualityFieldOptions>

/** Traits : un libellé, seul champ et prose, rien à recopier dans « Créer la version XX », aucune colonne. */
export const QUALITY_TRAIT_FIELD_OPTIONS = {
  label: { control: 'text' },
} as const satisfies Record<'label', QualityFieldOptions>
