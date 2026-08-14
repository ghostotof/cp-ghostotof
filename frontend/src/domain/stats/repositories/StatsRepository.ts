import type { Stat } from '../../portfolio/entities/Stat'
import type { Locale } from '../../portfolio/entities/Locale'

/**
 * Abstraction (DIP) dont dépend l'application. L'implémentation concrète
 * (HttpStatsRepository) est injectée au niveau du composition root
 * (main.ts), jamais instanciée directement par un composant. Séparée de
 * PortfolioContentRepository (qui reste 100% synchrone/statique) car cette
 * source de données est asynchrone (HTTP) — même raison que pour
 * ExperienceTechnologyRepository.
 */
export interface StatsRepository {
  list(locale: Locale): Promise<readonly Stat[]>
}
