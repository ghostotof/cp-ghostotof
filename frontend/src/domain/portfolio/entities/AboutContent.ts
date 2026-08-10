export interface AboutCard {
  readonly title: string
  readonly description: string
  readonly iconKey?: string
}

export interface AboutSiteSection {
  readonly eyebrow: string
  readonly cards: readonly AboutCard[]
}

export interface AboutMeSection {
  readonly eyebrow: string
  readonly technicalSubtitle: string
  readonly technicalCards: readonly AboutCard[]
  readonly personalSubtitle: string
  readonly personalCards: readonly AboutCard[]
  /** Visible uniquement aux utilisateurs authentifiés (cf. Goal #9 : rien de personnel sans authentification). */
  readonly hobbiesSubtitle: string
  readonly hobbiesCards: readonly AboutCard[]
}

export interface AboutContent {
  readonly site: AboutSiteSection
  readonly me: AboutMeSection
}
