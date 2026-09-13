import type { NavigationLink } from './NavigationLink'

/**
 * Une entrée de navigation qui ouvre un sous-menu plutôt qu'une page : un
 * libellé et les liens qu'elle regroupe. Introduite quand la barre a dépassé
 * ce qu'une ligne peut porter (issue #70) — regrouper est une décision
 * éditoriale prise dans StaticPortfolioContentRepository, pas dans l'en-tête.
 */
export interface NavigationGroup {
  readonly label: string
  readonly links: readonly NavigationLink[]
}
