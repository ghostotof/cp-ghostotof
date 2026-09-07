import type { WatchContent } from '../entities/WatchContent'

/**
 * Abstraction (DIP) dont dépend l'application. L'implémentation concrète
 * (HttpWatchRepository) est injectée au composition root (main.ts).
 *
 * Pas de paramètre `locale`, contrairement aux autres dépôts de contenu : une
 * version installée et sa date de fin de support sont des faits, pas des
 * traductions (décision D6). Seuls les libellés d'interface sont localisés, et
 * ils vivent dans les fichiers i18n.
 */
export interface WatchRepository {
  get(): Promise<WatchContent>
}
