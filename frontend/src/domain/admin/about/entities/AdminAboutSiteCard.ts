import type { Locale } from '../../../portfolio/entities/Locale'

/**
 * Forme à plat éditable d'une carte "À propos de ce site", distincte du DTO
 * public imbriqué AboutCard (sans id/locale/position).
 */
export interface AdminAboutSiteCard {
  readonly id: number
  readonly locale: Locale
  readonly title: string
  readonly description: string
  readonly iconKey: string | null
  readonly position: number
}
