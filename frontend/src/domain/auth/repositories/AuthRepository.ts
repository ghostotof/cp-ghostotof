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

  /**
   * Vrai quand le navigateur détient le jeton d'écriture (le cookie `XSRF-TOKEN`, non vide) : sans lui, la
   * première écriture part sans en-tête CSRF et prend un 403, lu « session finie ». Une session que `me()` dit
   * revenue mais qui n'a pas ce jeton (le `BEARER`, persistant, a survécu à un navigateur fermé qui a jeté le
   * cookie de session) n'est donc pas une session rétablie (#748, R8). L'application ne lit pas un cookie :
   * elle le demande à ce port.
   */
  hasWriteToken(): boolean
}
