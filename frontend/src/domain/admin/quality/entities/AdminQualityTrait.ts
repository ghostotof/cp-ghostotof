import type { Locale } from '../../../portfolio/entities/Locale'

/**
 * Forme à plat éditable d'un trait de qualité, distincte de QualityTrait
 * (contrat public en lecture seule, sans id/locale/position).
 *
 * Mêmes règles que pour AdminQualityPrinciple : `position` en lecture seule
 * (spec 0004, D3) et `translationGroup` (D1) comme clé d'ordre.
 */
export interface AdminQualityTrait {
  readonly id: string
  readonly locale: Locale
  readonly translationGroup: string
  readonly label: string
  readonly position: number
}
