import type { SiteIdentity } from '../entities/SiteIdentity'
import type { NavigationLink } from '../entities/NavigationLink'
import type { HeroContent } from '../entities/HeroContent'
import type { AboutContent } from '../entities/AboutContent'
import type { Technology } from '../entities/Technology'
import type { QualityPrinciple } from '../entities/QualityPrinciple'
import type { QualityTrait } from '../entities/QualityTrait'
import type { Stat } from '../entities/Stat'

/**
 * Abstraction (DIP) dont dépendent l'application et la présentation.
 * L'implémentation concrète (statique aujourd'hui, HTTP demain) est
 * injectée au niveau du composition root (main.ts), jamais instanciée
 * directement par un composant.
 */
export interface PortfolioContentRepository {
  getSiteIdentity(): SiteIdentity
  getNavigationLinks(): readonly NavigationLink[]
  getHeroContent(): HeroContent
  getAboutContent(): AboutContent
  getFeaturedTechnologies(): readonly Technology[]
  getAdditionalTechnologies(): readonly Technology[]
  getQualityPrinciples(): readonly QualityPrinciple[]
  getQualityTraits(): readonly QualityTrait[]
  getStats(): readonly Stat[]
}
