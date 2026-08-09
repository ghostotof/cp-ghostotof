/**
 * Texte d'introduction de la page Expériences. Le classement des
 * technologies lui-même vient du backend (cf. domain/experience) : cette
 * entité ne porte plus que le texte d'UI, qui reste statique/i18n côté
 * frontend.
 */
export interface ExperienceContent {
  readonly eyebrow: string
  readonly description: string
}
