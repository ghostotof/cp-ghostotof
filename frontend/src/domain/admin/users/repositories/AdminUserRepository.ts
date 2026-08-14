import type { AdminUser } from '../entities/AdminUser'

/**
 * Abstraction (DIP) dont dépend l'application. L'implémentation concrète
 * (HttpAdminUserRepository) est injectée au niveau du composition root
 * (main.ts), jamais instanciée directement par un composant. Pas de
 * `create()` : les comptes se créent exclusivement via la commande CLI
 * `app:user:create` (cf. backend CreateCpgUserCommand), jamais depuis le
 * backoffice.
 */
export interface AdminUserRepository {
  list(): Promise<readonly AdminUser[]>

  remove(id: number): Promise<void>

  changePassword(id: number, newPassword: string): Promise<void>
}
