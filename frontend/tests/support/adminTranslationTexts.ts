import fr from '../../src/infrastructure/i18n/locales/fr.json'

/**
 * Les textes FR du verrou d'un formulaire par entrée et de la traduction assistée, lus de `fr.json` par leur clé et
 * jamais recopiés (même principe qu'`accessRefusalTexts.ts`) : un libellé retouché dans le catalogue ne fait pas
 * rougir une dizaine de specs pour une virgule. Les specs les comparent entiers (`toEqual` sur `describedTexts()`) :
 * c'est l'identité du texte que désigne un `aria-describedby` qu'elles jugent, pas sa rédaction. La justesse des mots
 * eux-mêmes se juge à un seul endroit, `adminTranslationTexts.spec.ts`.
 */

/** `admin.entryLock.editing` : la langue d'une entrée existante ne se change pas. */
export const ENTRY_LOCK_EDITING_FR: string = fr.admin.entryLock.editing

/** `admin.entryLock.proposal` : le verrou d'une proposition — langue, « Version de », bouton de traduction, catégorie « moi ». */
export const ENTRY_LOCK_PROPOSAL_FR: string = fr.admin.entryLock.proposal

/** `admin.translation.discardPending` : l'attente d'un appel abandonné ; le bouton de traduction le désigne jusqu'à sa réponse. */
export const DISCARD_PENDING_FR: string = fr.admin.translation.discardPending

/** `admin.translation.proseReadonly` : l'aide des champs de prose pendant une traduction ; chacun le désigne jusqu'à la réponse. */
export const PROSE_READONLY_FR: string = fr.admin.translation.proseReadonly

/** `admin.translation.errors.unavailable` : le message d'une traduction en échec (503), tel que le formulaire le rend. */
export const TRANSLATION_UNAVAILABLE_FR: string = fr.admin.translation.errors.unavailable

/** `admin.quality.localeHelp` : l'aide permanente des sélecteurs de langue des principes et traits (#582), vraie en toute circonstance. */
export const LOCALE_HELP_FR: string = fr.admin.quality.localeHelp

/** `admin.about.localeHelp` : la même aide pour les cartes « moi » de « À propos » (le catalogue la répète par section). */
export const ABOUT_LOCALE_HELP_FR: string = fr.admin.about.localeHelp

/** `admin.about.settings.proposalLockedHelp` : le verrou des réglages « À propos » tant qu'une proposition est affichée. */
export const SETTINGS_PROPOSAL_LOCKED_FR: string = fr.admin.about.settings.proposalLockedHelp
