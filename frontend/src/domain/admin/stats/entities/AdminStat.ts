import type { Locale } from '../../../portfolio/entities/Locale'

/**
 * Forme à plat éditable d'une statistique, distincte de Stat (contrat public
 * en lecture seule, sans id/locale/position) : celle-ci reflète le DTO
 * backoffice (BackofficeStatResource) tel quel.
 */
export interface AdminStat {
  readonly id: number
  readonly locale: Locale
  readonly value: string
  readonly label: string
  readonly iconKey: string
  readonly position: number
}
