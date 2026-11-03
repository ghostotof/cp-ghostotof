import fr from '../../src/infrastructure/i18n/locales/fr.json'
import { createAppI18n } from '../../src/presentation/i18n'
import type { Locale } from '../../src/domain/portfolio/entities/Locale'

/**
 * Les libellés FR des pages d'admin (boutons, titres de formulaire, statut de l'ordre), **écrits une fois ici et lus du
 * catalogue** (`fr.json`) : un libellé retouché dans le catalogue ne fait pas rougir des dizaines de sites répartis sur plusieurs
 * specs, et une clé renommée casse la compilation de ce fichier seul. Les specs de pages cherchent le bouton par son texte
 * (`buttonLabelled`), jamais par un littéral français. La justesse des mots eux-mêmes se juge là où le texte est le sujet du test,
 * non dans ces constantes. Un texte qu'une seule spec lit (une erreur, un état vide) se lit de `fr.admin.…` dans cette spec.
 * Même principe qu'`adminTranslationTexts.ts` (les textes de la traduction assistée) et `entryFormLockTexts.ts`.
 */

/** Les sections d'admin à formulaire par entrée : `admin.<section>` porte `save`, `cancel` et la suppression (`delete` ou `deleteAction`). */
export type AdminEntrySection = 'incidents' | 'contributions' | 'caseStudies' | 'anonymousCv' | 'quality' | 'about' | 'watch'

/** Les quatre sections dont le formulaire a **un** titre de création et **un** d'édition (Qualité et À propos en ont un par tableau). */
export type AdminTitledSection = 'incidents' | 'contributions' | 'caseStudies' | 'anonymousCv'

/**
 * Les textes de `admin.order` que plusieurs specs lisent : la barre d'ordre est une seule pour tous les tableaux, distincte du
 * formulaire de l'entrée. `statusClean` / `statusDirty` sont les deux états de `OrderToolbar` ; `missingTranslation` le signal
 * d'une ligne sans version dans l'autre langue ; `lockedHint` l'aide d'un formulaire verrouillé par un ordre non enregistré.
 */
export const ORDER_TEXTS: {
  readonly save: string
  readonly cancel: string
  readonly statusClean: string
  readonly statusDirty: string
  readonly missingTranslation: string
  readonly lockedHint: string
} = {
  save: fr.admin.order.save,
  cancel: fr.admin.order.cancel,
  statusClean: fr.admin.order.status.clean,
  statusDirty: fr.admin.order.status.dirty,
  missingTranslation: fr.admin.order.missingTranslation,
  lockedHint: fr.admin.order.lockedHint,
}

/** Les trois boutons d'un formulaire par entrée : enregistrer, annuler, supprimer (celui du formulaire, non de la barre d'ordre). */
type EntryFormTexts = { readonly save: string; readonly cancel: string; readonly delete: string }

/**
 * Un seul endroit où le nom de la section devient une clé du catalogue ; la clé de la suppression change selon la section
 * (`deleteAction` pour les quatre contenus à titre unique et la veille, `delete` pour Qualité et À propos).
 */
const ENTRY_FORM_TEXTS = {
  incidents: { save: fr.admin.incidents.save, cancel: fr.admin.incidents.cancel, delete: fr.admin.incidents.deleteAction },
  contributions: {
    save: fr.admin.contributions.save,
    cancel: fr.admin.contributions.cancel,
    delete: fr.admin.contributions.deleteAction,
  },
  caseStudies: { save: fr.admin.caseStudies.save, cancel: fr.admin.caseStudies.cancel, delete: fr.admin.caseStudies.deleteAction },
  anonymousCv: { save: fr.admin.anonymousCv.save, cancel: fr.admin.anonymousCv.cancel, delete: fr.admin.anonymousCv.deleteAction },
  quality: { save: fr.admin.quality.save, cancel: fr.admin.quality.cancel, delete: fr.admin.quality.delete },
  about: { save: fr.admin.about.save, cancel: fr.admin.about.cancel, delete: fr.admin.about.delete },
  watch: { save: fr.admin.watch.save, cancel: fr.admin.watch.cancel, delete: fr.admin.watch.deleteAction },
} as const satisfies Record<AdminEntrySection, EntryFormTexts>

/** Les boutons du formulaire d'une entrée et de sa ligne : enregistrer, annuler, supprimer. */
export function entryFormTexts(section: AdminEntrySection): EntryFormTexts {
  return ENTRY_FORM_TEXTS[section]
}

/** Les titres du formulaire d'une section à titre unique : « Ajouter un incident », « Modifier l'incident »… */
const ENTRY_TITLES = {
  incidents: { create: fr.admin.incidents.create, edit: fr.admin.incidents.edit },
  contributions: { create: fr.admin.contributions.create, edit: fr.admin.contributions.edit },
  caseStudies: { create: fr.admin.caseStudies.create, edit: fr.admin.caseStudies.edit },
  anonymousCv: { create: fr.admin.anonymousCv.create, edit: fr.admin.anonymousCv.edit },
} as const satisfies Record<AdminTitledSection, { create: string; edit: string }>

/** Le titre de création et le titre d'édition du formulaire de `section` (le `<h2>` du formulaire). */
export function entryTitleTexts(section: AdminTitledSection): { readonly create: string; readonly edit: string } {
  return ENTRY_TITLES[section]
}

/**
 * L'annonce d'un déplacement dans la barre d'ordre (« Déplacé en position 2 sur 3 ») : le catalogue interpole la position et le
 * total, rendus par le vrai catalogue plutôt que recopiés, comme `createVersionLabel`.
 */
export function orderMovedLabel(position: number, count: number): string {
  return createAppI18n().global.t('admin.order.moved', { position, count })
}

/**
 * Le « Créer la version XX » d'une ligne dont la version `locale` manque : le catalogue interpole la langue **en majuscules**,
 * comme les pages la passent (`line.locale.toUpperCase()`). Rendu par le vrai catalogue, non recopié, donc une retouche du
 * libellé ou de son paramètre se suit seule.
 */
export function createVersionLabel(locale: Locale): string {
  return createAppI18n().global.t('admin.order.createVersion', { locale: locale.toUpperCase() })
}

/** Le « Proposer la version XX » du bouton de traduction (`TranslateEntryButton`), la langue cible en majuscules comme lui. */
export function proposeVersionLabel(locale: Locale): string {
  return createAppI18n().global.t('admin.translation.proposeVersion', { locale: locale.toUpperCase() })
}
