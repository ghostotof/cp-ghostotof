import type { AdminAboutSettings } from '../entities/AdminAboutSettings'
import type { Locale } from '../../../portfolio/entities/Locale'

export interface AdminAboutSettingsInput {
  siteEyebrow: string
  meEyebrow: string
  technicalSubtitle: string
  personalSubtitle: string
  hobbiesSubtitle: string
}

/**
 * Abstraction (DIP) dont dépend l'application. Pas de create/remove :
 * AboutSettings est un singleton par locale, seedé une fois côté backend (cf.
 * app:about:seed), jamais créé ni supprimé depuis le backoffice.
 */
export interface AdminAboutSettingsRepository {
  get(locale: Locale): Promise<AdminAboutSettings>

  update(locale: Locale, input: AdminAboutSettingsInput): Promise<AdminAboutSettings>
}
