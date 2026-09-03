import type { AdminUser } from '../entities/AdminUser'
import type { Locale } from '../../../portfolio/entities/Locale'

/**
 * Abstraction (DIP) dont dépend l'application. L'implémentation concrète
 * (HttpAdminUserRepository) est injectée au niveau du composition root
 * (main.ts), jamais instanciée directement par un composant.
 *
 * Pas de création directe (username + mot de passe) : elle reste réservée à la
 * commande CLI `app:user:create`. `invite()` crée un compte *en attente* à
 * partir d'une adresse e-mail et déclenche l'envoi d'un lien de définition de
 * mot de passe (cf. backend CpgUserInviter).
 */
export interface AdminUserRepository {
  list(): Promise<readonly AdminUser[]>

  /** Invite une personne par e-mail ; `locale` pilote la langue de l'e-mail et du lien. */
  invite(email: string, locale: Locale): Promise<AdminUser>

  /** Accorde (`grant = true`) ou retire le rôle ROLE_SUPER. */
  setSuperAdmin(id: number, grant: boolean): Promise<void>

  /** Régénère le jeton et renvoie l'e-mail d'invitation (comptes en attente uniquement). */
  resendInvitation(id: number, locale: Locale): Promise<void>

  remove(id: number): Promise<void>

  changePassword(id: number, newPassword: string): Promise<void>
}
