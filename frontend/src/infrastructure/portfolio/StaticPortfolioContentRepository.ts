import type { PortfolioContentRepository } from '../../domain/portfolio/repositories/PortfolioContentRepository'
import type { SiteIdentity } from '../../domain/portfolio/entities/SiteIdentity'
import type { NavigationLink } from '../../domain/portfolio/entities/NavigationLink'
import type { HeroContent } from '../../domain/portfolio/entities/HeroContent'
import type { Technology } from '../../domain/portfolio/entities/Technology'
import type { QualityPrinciple } from '../../domain/portfolio/entities/QualityPrinciple'
import type { QualityTrait } from '../../domain/portfolio/entities/QualityTrait'
import type { Stat } from '../../domain/portfolio/entities/Stat'

/**
 * Source de contenu statique (contenu repris de la maquette .claude/ressources/mockup-site-perso.png).
 * Remplaçable demain par une implémentation HTTP (ex. HttpPortfolioContentRepository consommant
 * l'API Symfony) sans aucun changement côté application/présentation, grâce à l'interface
 * PortfolioContentRepository.
 */
export class StaticPortfolioContentRepository implements PortfolioContentRepository {
  getSiteIdentity(): SiteIdentity {
    return {
      brandName: 'CP-Ghostotof',
      cvDownloadLabel: 'Télécharger mon CV',
      cvDownloadHref: '/cv.pdf',
    }
  }

  getNavigationLinks(): readonly NavigationLink[] {
    return [
      { label: 'Accueil', href: '#hero', isEnabled: true },
      { label: 'À propos', href: '#a-propos', isEnabled: false },
      { label: 'Compétences', href: '#technologies', isEnabled: true },
      { label: 'Expériences', href: '#experiences', isEnabled: false },
      { label: 'Contact', href: '#contact', isEnabled: false },
    ]
  }

  getHeroContent(): HeroContent {
    return {
      eyebrow: 'Développeur Web Senior',
      titleLead: 'Je construis des applications',
      titleAccent: 'robustes, performantes et évolutives.',
      description:
        'Développeur passionné par la création de solutions web modernes et maintenables, avec une approche orientée qualité et expérience utilisateur.',
      callsToAction: [
        { label: 'Découvrir mon approche', href: '#technologies', variant: 'primary', iconKey: 'arrow-right' },
        { label: 'Me contacter', href: '#contact', variant: 'secondary', iconKey: 'message-circle' },
      ],
      highlights: [
        { label: 'Code propre', iconKey: 'code' },
        { label: 'Architecture scalable', iconKey: 'layers' },
        { label: 'Performance', iconKey: 'zap' },
        { label: 'Sécurité', iconKey: 'shield' },
      ],
    }
  }

  getFeaturedTechnologies(): readonly Technology[] {
    return [
      { name: 'Symfony', description: 'Framework PHP', iconKey: 'symfony' },
      { name: 'Docker', description: 'Conteneurisation', iconKey: 'docker' },
      { name: 'PostgreSQL', description: 'Base de données', iconKey: 'postgresql' },
      { name: 'Symfony Messenger', description: 'Communication asynchrone', iconKey: 'mail' },
      { name: 'Vue.js', description: 'Framework JS', iconKey: 'vuejs' },
      { name: 'TypeScript', description: 'Typage statique', iconKey: 'typescript' },
    ]
  }

  getAdditionalTechnologies(): readonly Technology[] {
    return [
      { name: 'API Platform' },
      { name: 'Bootstrap' },
      { name: 'Git & GitHub' },
      { name: 'Nginx' },
      { name: 'Linux' },
      { name: 'CI/CD (GitHub Actions)' },
    ]
  }

  getQualityPrinciples(): readonly QualityPrinciple[] {
    return [
      {
        title: 'DDD',
        description:
          'Modélisation du domaine métier pour créer des applications alignées sur les besoins réels et faciles à faire évoluer.',
        iconKey: 'boxes',
      },
      {
        title: 'SOLID',
        description: 'Des bases solides pour un code flexible, maintenable et extensible dans le temps.',
        iconKey: 'columns-3',
      },
      {
        title: 'Design Patterns',
        description:
          'Utilisation de patrons de conception adaptés pour résoudre des problèmes récurrents avec élégance et efficacité.',
        iconKey: 'puzzle',
      },
    ]
  }

  getQualityTraits(): readonly QualityTrait[] {
    return [
      { label: 'Architecture propre' },
      { label: 'Maintenabilité' },
      { label: 'Évolutivité' },
      { label: 'Qualité du code' },
      { label: 'Tests automatisés' },
      { label: 'Revues de code' },
      { label: 'Documentation claire' },
    ]
  }

  getStats(): readonly Stat[] {
    return [
      { value: '+50K', label: 'Lignes de code', iconKey: 'code' },
      { value: '10+', label: 'Technologies maîtrisées', iconKey: 'box' },
      { value: '100%', label: 'Engagement qualité', iconKey: 'users' },
      { value: '∞', label: 'Passion', iconKey: 'infinity' },
    ]
  }
}
