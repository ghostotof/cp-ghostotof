import type { AuthenticatedUser } from '../entities/AuthenticatedUser'
import type { AuthSession } from '../entities/AuthSession'

/**
 * Abstraction (DIP) dont dépend l'application. L'implémentation concrète
 * (HttpAuthRepository) est injectée au niveau du composition root (main.ts),
 * jamais instanciée directement par un composant.
 */
export interface AuthRepository {
  login(username: string, password: string): Promise<AuthenticatedUser>

  logout(): Promise<void>

  /**
   * Interroge le backend pour savoir à quel palier (ADR 0003 D1) le cookie
   * httpOnly courant (jamais lisible en JS) donne encore droit. Ne rejette
   * ni en l'absence de session (palier `anonymous`) ni pour un jeton du
   * palier de base (`base`) : ce sont deux cas nominaux, pas des erreurs.
   * Rejette seulement sur une panne (réseau, 5xx), que l'appelant ne doit
   * pas confondre avec « anonyme ».
   */
  me(): Promise<AuthSession>
}
