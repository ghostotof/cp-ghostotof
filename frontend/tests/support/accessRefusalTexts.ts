import en from '../../src/infrastructure/i18n/locales/en.json'
import fr from '../../src/infrastructure/i18n/locales/fr.json'
import type { Locale } from '../../src/domain/portfolio/entities/Locale'

/** Les catalogues par langue, lus tels que les fichiers JSON les écrivent. */
const CATALOGUES = { fr, en } as const satisfies Record<Locale, unknown>

export interface AccessRefusalTexts {
  readonly earlierSaveRefused: string
  readonly accessRefused: string
  readonly switchAccount: string
}

/**
 * Les textes de l'alerte « accès refusé » du backoffice dans `locale` (`common.earlierSaveRefused`, `common.accessRefused`,
 * `common.switchAccount`), lus du catalogue par leur clé et jamais recopiés : un libellé retouché ne fait pas rougir les specs
 * qui les lisent pour une virgule. Ils se comparent entiers (le texte complet, jamais un extrait) : ces specs jugent l'identité
 * du texte affiché (l'avis d'un enregistrement précédent, ou le refus courant), non sa rédaction.
 */
export function accessRefusalTexts(locale: Locale): AccessRefusalTexts {
  const { common } = CATALOGUES[locale]

  return {
    earlierSaveRefused: common.earlierSaveRefused,
    accessRefused: common.accessRefused,
    switchAccount: common.switchAccount,
  }
}
