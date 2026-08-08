import type { SiteIdentity } from '../entities/SiteIdentity'
import type { NavigationLink } from '../entities/NavigationLink'
import type { HeroContent } from '../entities/HeroContent'
import type { AboutContent } from '../entities/AboutContent'
import type { Technology } from '../entities/Technology'
import type { QualityPrinciple } from '../entities/QualityPrinciple'
import type { QualityTrait } from '../entities/QualityTrait'
import type { Stat } from '../entities/Stat'
import type { Locale } from '../entities/Locale'

/**
 * Abstraction (DIP) dont dépendent l'application et la présentation.
 * L'implémentation concrète (statique aujourd'hui, HTTP demain) est
 * injectée au niveau du composition root (main.ts), jamais instanciée
 * directement par un composant. Chaque méthode est paramétrée par la
 * locale courante : le site étant multilingue, le contenu n'a pas de
 * sens indépendamment d'une langue.
 */
export interface PortfolioContentRepository {
  /** Ne dépend pas de la locale : le nom de marque est identique dans toutes les langues. */
  getSiteIdentity(): SiteIdentity
  getNavigationLinks(locale: Locale): readonly NavigationLink[]
  getHeroContent(locale: Locale): HeroContent
  getAboutContent(locale: Locale): AboutContent
  getFeaturedTechnologies(locale: Locale): readonly Technology[]
  getAdditionalTechnologies(locale: Locale): readonly Technology[]
  getQualityPrinciples(locale: Locale): readonly QualityPrinciple[]
  getQualityTraits(locale: Locale): readonly QualityTrait[]
  getStats(locale: Locale): readonly Stat[]
}
