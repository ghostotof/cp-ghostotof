import type { Incident } from '../entities/Incident'
import type { Locale } from '../../portfolio/entities/Locale'

/**
 * Abstraction (DIP) dont dépend l'application. L'implémentation concrète
 * (HttpIncidentRepository) est injectée au composition root (main.ts).
 */
export interface IncidentRepository {
  list(locale: Locale): Promise<readonly Incident[]>
}
