/**
 * Contenu long-format (mentions légales, politique de confidentialité) : une
 * suite de sections titrées, à la différence des grilles de cards utilisées
 * par `AboutContent`. Structure volontairement générique pour être réutilisée
 * par les deux pages légales.
 */
export interface LegalSection {
  readonly heading: string
  readonly paragraphs: readonly string[]
  readonly list?: readonly string[]
}

export interface LegalPageContent {
  readonly eyebrow: string
  readonly title: string
  readonly lastUpdated: string
  readonly sections: readonly LegalSection[]
}
