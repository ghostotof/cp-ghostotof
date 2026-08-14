import type { Locale } from '../../../portfolio/entities/Locale'

export type AdminAboutMeCardCategory = 'technical' | 'personal' | 'hobby'

/**
 * Forme à plat éditable d'une carte "À propos de moi", distincte du DTO
 * public imbriqué AboutCard : celle-ci porte en plus `category`, qui range la
 * carte dans technicalCards/personalCards/hobbiesCards côté affichage public.
 */
export interface AdminAboutMeCard {
  readonly id: number
  readonly locale: Locale
  readonly category: AdminAboutMeCardCategory
  readonly title: string
  readonly description: string
  readonly iconKey: string | null
  readonly position: number
}
