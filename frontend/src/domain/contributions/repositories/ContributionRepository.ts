import type { Contribution } from '../entities/Contribution'
import type { Locale } from '../../portfolio/entities/Locale'

/**
 * Abstraction (DIP) dont dépend l'application. L'implémentation concrète
 * (HttpContributionRepository) est injectée au composition root (main.ts),
 * jamais instanciée par un composant. Séparée de PortfolioContentRepository
 * (100 % synchrone/statique) car cette source est asynchrone — même raison que
 * QualityContentRepository.
 */
export interface ContributionRepository {
  list(locale: Locale): Promise<readonly Contribution[]>
}
