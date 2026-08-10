import type { AuthenticatedUser } from '../entities/AuthenticatedUser'

/**
 * Abstraction (DIP) dont dépend l'application. L'implémentation concrète
 * (HttpAuthRepository) est injectée au niveau du composition root (main.ts),
 * jamais instanciée directement par un composant.
 */
export interface AuthRepository {
  login(username: string, password: string): Promise<AuthenticatedUser>

  logout(): Promise<void>

  /**
   * Interroge le backend pour savoir si le cookie httpOnly courant (jamais
   * lisible en JS) correspond encore à une session valide. Ne rejette pas en
   * l'absence de session : retourne `null`, un cas nominal "non connecté".
   */
  me(): Promise<AuthenticatedUser | null>
}
