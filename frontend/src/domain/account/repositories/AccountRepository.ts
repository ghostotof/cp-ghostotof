/**
 * Abstraction (DIP) du parcours public « je définis mon mot de passe via le
 * lien reçu par e-mail ». L'implémentation concrète (HttpAccountRepository) est
 * injectée au composition root (main.ts). Endpoint public : aucune session,
 * aucun CSRF. Les échecs sont signalés par `PasswordSetupLinkError`
 * (domain/account/errors), dont le `reason` guide le message affiché.
 */
export interface AccountRepository {
  /**
   * Vérifie qu'un jeton de définition de mot de passe est encore exploitable
   * (avant d'afficher le formulaire).
   *
   * @throws PasswordSetupLinkError `invalid` / `expired` / `rate-limited` / `unknown`
   */
  validateSetupToken(token: string): Promise<void>

  /**
   * Consomme le jeton et définit le mot de passe. Le jeton devient inutilisable.
   *
   * @throws PasswordSetupLinkError `invalid` / `expired` / `weak-password` / `rate-limited` / `unknown`
   */
  completePasswordSetup(token: string, password: string): Promise<void>
}
