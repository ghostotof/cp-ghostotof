import type { Locale } from '../../../portfolio/entities/Locale'

/**
 * Forme à plat éditable d'un principe de qualité, distincte de
 * QualityPrinciple (contrat public en lecture seule, sans id/locale/position).
 */
export interface AdminQualityPrinciple {
  readonly id: number
  readonly locale: Locale
  readonly title: string
  readonly description: string
  readonly iconKey: string
  readonly position: number
}
