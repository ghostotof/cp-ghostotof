const CSRF_COOKIE_NAME = 'XSRF-TOKEN'

/**
 * Lit le cookie XSRF-TOKEN posé par le backend au login (non httpOnly,
 * contrairement au JWT). Sa valeur est renvoyée telle quelle dans le header
 * X-XSRF-TOKEN par HttpAuthRepository pour les requêtes qui changent l'état
 * (pattern double-submit cookie, cf. backend App\Security\Jwt\CsrfCookieRequestSubscriber).
 */
export function readCsrfToken(): string | null {
  const match = document.cookie
    .split('; ')
    .find((entry) => entry.startsWith(`${CSRF_COOKIE_NAME}=`))

  return match ? decodeURIComponent(match.slice(CSRF_COOKIE_NAME.length + 1)) : null
}
