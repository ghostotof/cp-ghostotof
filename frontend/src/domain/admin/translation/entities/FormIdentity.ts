import type { Locale } from '../../../portfolio/entities/Locale'

/**
 * Ce qui fait l'identité d'un formulaire de contenu, au sens de la traduction assistée : une traduction partie d'un
 * formulaire n'a de sens que pour celui-là. La prose n'en fait pas partie (la page la met en lecture seule pendant
 * l'appel).
 *
 * - `entry` : l'entrée éditée, `null` pour une création ;
 * - `locale` : la langue du formulaire ;
 * - `group` : le groupe de traduction de la source (« Créer la version XX », une proposition), `null` sinon ;
 * - `generation` : le numéro du formulaire, que la remise à zéro fera avancer ; `0` tant qu'aucune ne le fait.
 */
export interface FormIdentity {
  readonly entry: string | null
  readonly locale: Locale
  readonly group: string | null
  readonly generation: number
}

/**
 * Deux identités sont la même quand chacun de leurs champs l'est. Comparée champ par champ, jamais par une
 * sérialisation : celle-ci dépend de l'ordre d'écriture des clés, qu'aucun type ne fixe.
 */
export function sameFormIdentity(a: FormIdentity, b: FormIdentity): boolean {
  return a.entry === b.entry && a.locale === b.locale && a.group === b.group && a.generation === b.generation
}
