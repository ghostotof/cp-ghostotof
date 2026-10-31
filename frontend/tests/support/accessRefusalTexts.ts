import fr from '../../src/infrastructure/i18n/locales/fr.json'

/**
 * Les deux textes FR de l'alerte « accès refusé » du backoffice, lus de `fr.json` par leur clé et jamais recopiés : un
 * libellé retouché dans le catalogue ne fait pas rougir dix specs pour une virgule. Ils se comparent entiers
 * (`toBe`, jamais `toContain`) : c'est l'identité du texte affiché (l'avis d'un enregistrement précédent, ou le refus
 * courant) que ces specs jugent, pas sa rédaction.
 */

/** `common.earlierSaveRefused` : un enregistrement refusé pendant que la personne avait quitté le backoffice. */
export const EARLIER_SAVE_REFUSED_FR: string = fr.common.earlierSaveRefused

/** `common.accessRefused` : le refus courant d'une lecture ou d'une écriture. */
export const ACCESS_REFUSED_FR: string = fr.common.accessRefused
