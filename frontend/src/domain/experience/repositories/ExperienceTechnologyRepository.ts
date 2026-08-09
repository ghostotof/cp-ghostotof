import type { ExperienceTechnology } from '../entities/ExperienceTechnology'
import type { Locale } from '../../portfolio/entities/Locale'

/**
 * Abstraction (DIP) dont dépend l'application. L'implémentation concrète
 * (HttpExperienceTechnologyRepository) est injectée au niveau du composition
 * root (main.ts), jamais instanciée directement par un composant. Séparée de
 * PortfolioContentRepository (qui reste 100% synchrone/statique) car cette
 * source de données est asynchrone (HTTP) — même raison que pour CvRepository.
 */
export interface ExperienceTechnologyRepository {
  list(locale: Locale): Promise<readonly ExperienceTechnology[]>
}
