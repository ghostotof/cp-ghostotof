import fr from '../../src/infrastructure/i18n/locales/fr.json'
import { createAppI18n } from '../../src/presentation/i18n'
import type { Locale } from '../../src/domain/portfolio/entities/Locale'

/**
 * Les libellés FR des boutons des pages d'admin, **écrits une fois ici et lus du catalogue** (`fr.json`) : un libellé retouché
 * dans le catalogue ne fait pas rougir des dizaines de sites répartis sur plusieurs specs, et une clé renommée casse la
 * compilation de ce fichier seul. Les specs de pages cherchent le bouton par son texte (`buttonLabelled`), jamais par un
 * littéral français. La justesse des mots eux-mêmes se juge là où le texte est le sujet du test, non dans ces constantes.
 * Même principe qu'`adminTranslationTexts.ts` (les textes de la traduction assistée) et `entryFormLockTexts.ts`.
 */

/** Les sections d'admin à formulaire par entrée, dont `admin.<section>` porte `save`, `cancel` et `deleteAction`. */
export type AdminEntrySection = 'incidents' | 'contributions' | 'caseStudies' | 'anonymousCv'

/** Les textes de `admin.order` : la barre d'ordre est une seule pour tous les tableaux, distincte du formulaire de l'entrée. */
export const ORDER_TEXTS: { readonly save: string; readonly cancel: string } = {
  save: fr.admin.order.save,
  cancel: fr.admin.order.cancel,
}

/** Le bloc `admin.<section>` de chaque section : un seul endroit où le nom de la section devient une clé du catalogue. */
const SECTION_TEXTS = {
  incidents: fr.admin.incidents,
  contributions: fr.admin.contributions,
  caseStudies: fr.admin.caseStudies,
  anonymousCv: fr.admin.anonymousCv,
} as const satisfies Record<AdminEntrySection, { save: string; cancel: string; deleteAction: string }>

/**
 * Les boutons du formulaire d'une entrée et de sa ligne : enregistrer, annuler, supprimer. Une section dont le catalogue nomme
 * la suppression `delete` (Qualité, À propos) s'ajoute avec son propre accès, au type `AdminEntrySection` et à `SECTION_TEXTS`.
 */
export function entryFormTexts(section: AdminEntrySection): { save: string; cancel: string; delete: string } {
  const texts = SECTION_TEXTS[section]
  return { save: texts.save, cancel: texts.cancel, delete: texts.deleteAction }
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
