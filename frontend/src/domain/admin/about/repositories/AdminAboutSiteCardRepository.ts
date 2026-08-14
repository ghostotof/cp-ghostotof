import type { AdminAboutSiteCard } from '../entities/AdminAboutSiteCard'
import type { Locale } from '../../../portfolio/entities/Locale'

export interface AdminAboutSiteCardInput {
  locale: Locale
  title: string
  description: string
  iconKey: string | null
  position: number
}

/**
 * Abstraction (DIP) dont dépend l'application. CRUD réservé au backoffice
 * (ROLE_SUPER, cf. /api/backoffice/about/site-cards).
 */
export interface AdminAboutSiteCardRepository {
  list(locale: Locale): Promise<readonly AdminAboutSiteCard[]>

  create(input: AdminAboutSiteCardInput): Promise<AdminAboutSiteCard>

  update(id: number, input: AdminAboutSiteCardInput): Promise<AdminAboutSiteCard>

  remove(id: number): Promise<void>
}
