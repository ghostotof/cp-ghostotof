import type { AdminAboutMeCard, AdminAboutMeCardCategory } from '../entities/AdminAboutMeCard'
import type { Locale } from '../../../portfolio/entities/Locale'

export interface AdminAboutMeCardInput {
  locale: Locale
  category: AdminAboutMeCardCategory
  title: string
  description: string
  iconKey: string | null
  position: number
}

/**
 * Abstraction (DIP) dont dépend l'application. CRUD réservé au backoffice
 * (ROLE_SUPER, cf. /api/backoffice/about/me-cards). `category` est un filtre
 * optionnel de list() : sans lui, les cartes des 3 catégories sont renvoyées
 * mélangées.
 */
export interface AdminAboutMeCardRepository {
  list(locale: Locale, category?: AdminAboutMeCardCategory): Promise<readonly AdminAboutMeCard[]>

  create(input: AdminAboutMeCardInput): Promise<AdminAboutMeCard>

  update(id: number, input: AdminAboutMeCardInput): Promise<AdminAboutMeCard>

  remove(id: number): Promise<void>
}
