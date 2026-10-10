/**
 * Levée par AuthRepository.login() sur un 429 : trop de tentatives de
 * connexion (issue #399). Cas distinct d'identifiants invalides parce que la
 * consigne n'est pas la même — attendre suffit, le mot de passe n'a même pas
 * été vérifié.
 *
 * Deux émetteurs mènent ici : le `login_throttling` de Symfony (5 échecs par
 * quart d'heure et par couple IP + identifiant, 429 `/errors/rate-limited`
 * avec `Retry-After`) et la zone nginx `login` (même `type`, sans
 * `Retry-After`). En préprod et en prod le front partage l'origine de l'API,
 * le 429 de nginx est donc lisible (#368) ; en dev seulement, le navigateur le
 * rejette et le cas retombe sur l'erreur générique.
 */
export class LoginRateLimitedError extends Error {
  readonly retryAfterSeconds: number | null

  /**
   * @param retryAfterSeconds délai avant de réessayer, quand le serveur le
   *   fournit (le throttling de Symfony, jusqu'à 15 min) ; `null` pour la zone
   *   nginx, qui n'en pose pas.
   */
  constructor(retryAfterSeconds: number | null = null) {
    super('Too many login attempts')
    this.name = 'LoginRateLimitedError'
    this.retryAfterSeconds = retryAfterSeconds
  }
}
