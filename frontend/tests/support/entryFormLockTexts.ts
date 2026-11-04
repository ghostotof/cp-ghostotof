import fr from '../../src/infrastructure/i18n/locales/fr.json'

/**
 * Les textes FR du verrou d'un formulaire par entrée (langue, catégorie, « Annuler », bouton de traduction, réglages), lus de
 * `fr.json` par leur clé et jamais recopiés (même principe qu'`accessRefusalTexts.ts`) : un libellé retouché dans le
 * catalogue ne fait pas rougir une dizaine de specs pour une virgule. Les specs les comparent entiers (`toEqual` sur
 * `describedTexts()`) : c'est l'identité du texte que désigne un `aria-describedby` qu'elles jugent, pas sa rédaction.
 * Ceux de la traduction elle-même (attente, prose, échec) sont dans `adminTranslationTexts.ts`.
 */

/**
 * Le libellé du bouton « Annuler » de chaque formulaire par entrée : le catalogue le dit par une clé par page (`cancel` de
 * `quality`, `about`, `contributions`, `anonymousCv`, `caseStudies`, `incidents`), le support cherche celui du formulaire
 * qu'il conduit sans savoir de quelle page il vient. Retoucher un de ces libellés ne fait donc pas rougir les cas partagés.
 */
export const ENTRY_FORM_CANCEL_LABELS_FR: readonly string[] = [
  ...new Set([
    fr.admin.quality.cancel,
    fr.admin.about.cancel,
    fr.admin.contributions.cancel,
    fr.admin.anonymousCv.cancel,
    fr.admin.caseStudies.cancel,
    fr.admin.incidents.cancel,
  ]),
]

/**
 * `admin.translation.proposeVersion` sans son paramètre : le début du libellé du bouton de traduction (« Proposer la version »),
 * que la langue cible complète (« … EN »). Le bouton se reconnaît à ce début, jamais à un littéral du support.
 */
export const PROPOSE_VERSION_PREFIX_FR: string = fr.admin.translation.proposeVersion.split('{locale}')[0].trimEnd()

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
