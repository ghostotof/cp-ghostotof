import fr from '../../src/infrastructure/i18n/locales/fr.json'

/**
 * Les textes FR de la traduction assistée (attente d'un appel abandonné, prose en lecture seule, échec), lus de `fr.json`
 * par leur clé et jamais recopiés (même principe qu'`accessRefusalTexts.ts`) : un libellé retouché dans le catalogue ne
 * fait pas rougir une dizaine de specs pour une virgule. Les specs les comparent entiers (`toEqual` sur
 * `describedTexts()`) : c'est l'identité du texte que désigne un `aria-describedby` qu'elles jugent, pas sa rédaction.
 * La justesse des mots eux-mêmes se juge à un seul endroit, `tests/infrastructure/i18n/adminTranslationTexts.spec.ts`.
 * Ceux du verrou d'un formulaire sont dans `entryFormLockTexts.ts`.
 */

/** `admin.translation.expiredCallPending` : l'attente d'un appel abandonné ; le bouton de traduction le désigne jusqu'à sa réponse. */
export const EXPIRED_CALL_PENDING_FR: string = fr.admin.translation.expiredCallPending

/** `admin.translation.proseReadonly` : l'aide des champs de prose pendant une traduction ; chacun le désigne jusqu'à la réponse. */
export const PROSE_READONLY_FR: string = fr.admin.translation.proseReadonly

/** `admin.translation.errors.unavailable` : le message d'une traduction en échec (503), tel que le formulaire le rend. */
export const TRANSLATION_UNAVAILABLE_FR: string = fr.admin.translation.errors.unavailable

/** `admin.translation.inProgress` : le libellé du bouton de traduction tant que l'appel est en vol, à la place de « Proposer… ». */
export const TRANSLATION_IN_PROGRESS_FR: string = fr.admin.translation.inProgress
