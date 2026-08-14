import type { SiteIdentity } from '../entities/SiteIdentity'
import type { NavigationLink } from '../entities/NavigationLink'
import type { HeroContent } from '../entities/HeroContent'
import type { ExperienceContent } from '../entities/ExperienceContent'
import type { Technology } from '../entities/Technology'
import type { Locale } from '../entities/Locale'
import type { LegalPageContent } from '../entities/LegalPageContent'

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
  getExperienceContent(locale: Locale): ExperienceContent
  getFeaturedTechnologies(locale: Locale): readonly Technology[]
  getAdditionalTechnologies(locale: Locale): readonly Technology[]
  getLegalNoticeContent(locale: Locale): LegalPageContent
  getPrivacyPolicyContent(locale: Locale): LegalPageContent
}
