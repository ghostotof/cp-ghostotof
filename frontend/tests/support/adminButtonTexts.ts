import fr from '../../src/infrastructure/i18n/locales/fr.json'
import { renderedMessage } from './renderedMessage'
import type { Locale } from '../../src/domain/portfolio/entities/Locale'

/**
 * Les libellés FR des pages d'admin (boutons, titres de formulaire, statut de l'ordre), **écrits une fois ici et lus du
 * catalogue** (`fr.json`) : un libellé retouché dans le catalogue ne fait pas rougir les specs qui le lisent, et une clé
 * renommée casse la compilation de ce fichier seul. Les specs de pages cherchent le bouton par son texte (`buttonLabelled`),
 * jamais par un littéral français. La justesse des mots eux-mêmes se juge là où le texte est le sujet du test, non dans ces
 * constantes. Comment un spec lit le catalogue : `.claude/rules/frontend.md`, « Tests read the catalogue ».
 */

/** Les textes de `admin.order` que plusieurs specs lisent : la barre d'ordre est la même pour tous les tableaux. */
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

/** Enregistrer, annuler, supprimer : les boutons du formulaire d'une entrée (non ceux de la barre d'ordre). */
type EntryFormTexts = { readonly save: string; readonly cancel: string; readonly delete: string }

/**
 * Les boutons du formulaire de chaque section, par le nom de la section : un seul endroit où ce nom devient une clé du
 * catalogue, dont celle de la suppression change (`deleteAction` pour les contenus à titre unique et la veille, `delete`
 * pour Qualité et À propos).
 */
export const ENTRY_FORM_TEXTS = {
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
} as const satisfies Record<string, EntryFormTexts>

/** Le titre de création et celui d'édition (le `<h2>`) du formulaire d'une section à titre unique : « Ajouter un incident »… */
export const ENTRY_TITLES = {
  incidents: { create: fr.admin.incidents.create, edit: fr.admin.incidents.edit },
  contributions: { create: fr.admin.contributions.create, edit: fr.admin.contributions.edit },
  caseStudies: { create: fr.admin.caseStudies.create, edit: fr.admin.caseStudies.edit },
  anonymousCv: { create: fr.admin.anonymousCv.create, edit: fr.admin.anonymousCv.edit },
} as const satisfies Record<string, { create: string; edit: string }>

/** « Déplacé en position 2 sur 3 » : l'annonce d'un déplacement dans la barre d'ordre. */
export function orderMovedLabel(position: number, count: number): string {
  return renderedMessage('fr', 'admin.order.moved', { position, count })
}

/** Le « Créer la version XX » d'une ligne dont la version `locale` manque ; la langue est en majuscules, comme les pages la passent. */
export function createVersionLabel(locale: Locale): string {
  return renderedMessage('fr', 'admin.order.createVersion', { locale: locale.toUpperCase() })
}

/** Le « Proposer la version XX » du bouton de traduction (`TranslateEntryButton`), la langue cible en majuscules comme lui. */
export function proposeVersionLabel(locale: Locale): string {
  return renderedMessage('fr', 'admin.translation.proposeVersion', { locale: locale.toUpperCase() })
}
