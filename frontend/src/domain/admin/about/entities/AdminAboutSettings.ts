import type { Locale } from '../../../portfolio/entities/Locale'

/**
 * Forme éditable des textes de section "À propos" (singleton par locale, pas
 * de create/delete côté backoffice — cf. BackofficeAboutSettingsResource).
 */
export interface AdminAboutSettings {
  readonly locale: Locale
  readonly siteEyebrow: string
  readonly meEyebrow: string
  readonly technicalSubtitle: string
  readonly personalSubtitle: string
  readonly hobbiesSubtitle: string
}
