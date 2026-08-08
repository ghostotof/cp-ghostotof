import type { PortfolioContentRepository } from '../../domain/portfolio/repositories/PortfolioContentRepository'
import type { SiteIdentity } from '../../domain/portfolio/entities/SiteIdentity'
import type { NavigationLink } from '../../domain/portfolio/entities/NavigationLink'
import type { HeroContent } from '../../domain/portfolio/entities/HeroContent'
import type { AboutContent } from '../../domain/portfolio/entities/AboutContent'
import type { Technology } from '../../domain/portfolio/entities/Technology'
import type { QualityPrinciple } from '../../domain/portfolio/entities/QualityPrinciple'
import type { QualityTrait } from '../../domain/portfolio/entities/QualityTrait'
import type { Stat } from '../../domain/portfolio/entities/Stat'
import type { Locale } from '../../domain/portfolio/entities/Locale'
import type { PortfolioLocaleContent } from './content/PortfolioLocaleContent'
import frContent from './content/fr'
import enContent from './content/en'
import frMessages from '../i18n/locales/fr.json'
import enMessages from '../i18n/locales/en.json'

type UiMessages = typeof frMessages

const CONTENT: Record<Locale, PortfolioLocaleContent> = { fr: frContent, en: enContent }
const MESSAGES: Record<Locale, UiMessages> = { fr: frMessages, en: enMessages }

/**
 * Source de contenu statique (contenu repris de la maquette .claude/ressources/mockup-site-perso.png).
 * Remplaçable demain par une implémentation HTTP (ex. HttpPortfolioContentRepository consommant
 * l'API Symfony) sans aucun changement côté application/présentation, grâce à l'interface
 * PortfolioContentRepository.
 *
 * N'importe jamais le runtime vue-i18n (`presentation/i18n`) : cette classe reste de
 * l'infrastructure pure, elle lit directement les fichiers de messages/contenu par
 * locale plutôt que de dépendre de la couche présentation.
 */
export class StaticPortfolioContentRepository implements PortfolioContentRepository {
  getSiteIdentity(locale: Locale): SiteIdentity {
    return {
      brandName: 'CP-Ghostotof',
      cvDownloadLabel: MESSAGES[locale].common.downloadCv,
      cvDownloadHref: '/cv.pdf',
    }
  }

  getNavigationLinks(locale: Locale): readonly NavigationLink[] {
    const nav = MESSAGES[locale].nav
    return [
      { label: nav.home, to: `/${locale}`, isEnabled: true },
      { label: nav.skills, to: `/${locale}#technologies`, isEnabled: true },
      { label: nav.experiences, to: `/${locale}#experiences`, isEnabled: false },
      { label: nav.contact, to: `/${locale}#contact`, isEnabled: false },
      { label: nav.about, to: `/${locale}/about`, isEnabled: true },
    ]
  }

  getHeroContent(locale: Locale): HeroContent {
    return CONTENT[locale].hero
  }

  getAboutContent(locale: Locale): AboutContent {
    return CONTENT[locale].about
  }

  getFeaturedTechnologies(locale: Locale): readonly Technology[] {
    return CONTENT[locale].technologies.featured
  }

  getAdditionalTechnologies(locale: Locale): readonly Technology[] {
    return CONTENT[locale].technologies.additional
  }

  getQualityPrinciples(locale: Locale): readonly QualityPrinciple[] {
    return CONTENT[locale].quality.principles
  }

  getQualityTraits(locale: Locale): readonly QualityTrait[] {
    return CONTENT[locale].quality.traits
  }

  getStats(locale: Locale): readonly Stat[] {
    return CONTENT[locale].stats
  }
}
