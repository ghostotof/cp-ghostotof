import fr from '../../src/infrastructure/i18n/locales/fr.json'

/**
 * Les textes FR du verrou d'un formulaire par entrée (langue, catégorie, bouton de traduction, réglages), lus de
 * `fr.json` par leur clé et jamais recopiés (même principe qu'`accessRefusalTexts.ts`) : un libellé retouché dans le
 * catalogue ne fait pas rougir une dizaine de specs pour une virgule. Les specs les comparent entiers (`toEqual` sur
 * `describedTexts()`) : c'est l'identité du texte que désigne un `aria-describedby` qu'elles jugent, pas sa rédaction.
 * Ceux de la traduction elle-même (attente, prose, échec) sont dans `adminTranslationTexts.ts`.
 */

/** `admin.entryLock.editing` : la langue d'une entrée existante ne se change pas. */
export const ENTRY_LOCK_EDITING_FR: string = fr.admin.entryLock.editing

/** `admin.entryLock.proposal` : le verrou d'une proposition — langue, « Version de », bouton de traduction, catégorie « moi ». */
export const ENTRY_LOCK_PROPOSAL_FR: string = fr.admin.entryLock.proposal

/** `admin.quality.localeHelp` : l'aide permanente des sélecteurs de langue des principes et traits (#582), vraie en toute circonstance. */
export const QUALITY_LOCALE_HELP_FR: string = fr.admin.quality.localeHelp

/** `admin.about.localeHelp` : la même aide pour les cartes « moi » de « À propos » (le catalogue la répète par section). */
export const ABOUT_LOCALE_HELP_FR: string = fr.admin.about.localeHelp

/** `admin.about.settings.proposalLockedHelp` : le verrou des réglages « À propos » tant qu'une proposition est affichée. */
export const SETTINGS_PROPOSAL_LOCKED_FR: string = fr.admin.about.settings.proposalLockedHelp
