import type { Locale } from '../../../portfolio/entities/Locale'

/**
 * Forme à plat éditable d'un trait de qualité, distincte de QualityTrait
 * (contrat public en lecture seule, sans id/locale/position).
 */
export interface AdminQualityTrait {
  readonly id: number
  readonly locale: Locale
  readonly label: string
  readonly position: number
}
