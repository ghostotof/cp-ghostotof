import { inject, type InjectionKey } from 'vue'
import type { PortfolioContentRepository } from '../../domain/portfolio/repositories/PortfolioContentRepository'

/**
 * Clé d'injection : le composition root (main.ts) fournit l'implémentation
 * concrète via app.provide(PORTFOLIO_CONTENT_REPOSITORY, ...). Ce composable
 * ne connaît que l'abstraction du domaine (DIP).
 */
export const PORTFOLIO_CONTENT_REPOSITORY: InjectionKey<PortfolioContentRepository> = Symbol(
  'PortfolioContentRepository',
)

/**
 * Use-case applicatif : expose le contenu de la landing page aux composants
 * de présentation, sans qu'aucun d'eux n'ait à connaître la source des données.
 */
export function usePortfolioContent() {
  const repository = inject(PORTFOLIO_CONTENT_REPOSITORY)

  if (!repository) {
    throw new Error(
      "PortfolioContentRepository n'a pas été fourni. " +
        'Vérifiez que app.provide(PORTFOLIO_CONTENT_REPOSITORY, ...) est bien appelé dans main.ts.',
    )
  }

  return {
    siteIdentity: repository.getSiteIdentity(),
    navigationLinks: repository.getNavigationLinks(),
    heroContent: repository.getHeroContent(),
    featuredTechnologies: repository.getFeaturedTechnologies(),
    additionalTechnologies: repository.getAdditionalTechnologies(),
    qualityPrinciples: repository.getQualityPrinciples(),
    qualityTraits: repository.getQualityTraits(),
    stats: repository.getStats(),
  }
}
