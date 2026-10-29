const CSRF_COOKIE_NAME = 'XSRF-TOKEN'

/**
 * Lit le cookie XSRF-TOKEN posé par le backend au login (non httpOnly,
 * contrairement au JWT). Sa valeur est renvoyée telle quelle dans le header
 * X-XSRF-TOKEN par HttpAuthRepository pour les requêtes qui changent l'état
 * (pattern double-submit cookie, cf. backend App\Security\Jwt\CsrfCookieRequestSubscriber).
 *
 * Une valeur qui ne se décode pas (`%E0`) vaut **absente**, comme un cookie vide : le serveur n'émet que
 * des jetons signés, sans caractère à encoder, donc une telle valeur n'est jamais la sienne. La rendre
 * telle quelle n'aiderait pas (le serveur, qui décode le cookie, la refuserait aussi) ; la laisser lever
 * `URIError` ferait échouer chaque écriture avant tout envoi (#748, F13).
 */
export function readCsrfToken(): string | null {
  const match = document.cookie
    .split('; ')
    .find((entry) => entry.startsWith(`${CSRF_COOKIE_NAME}=`))

  if (undefined === match) {
    return null
  }

  try {
    return decodeURIComponent(match.slice(CSRF_COOKIE_NAME.length + 1))
  } catch (error) {
    if (error instanceof URIError) {
      return null
    }

    throw error
  }
}

/**
 * « Avec jeton » : la seule définition, pour l'en-tête envoyé (`sessionAwareFetch`) comme pour le
 * jeton d'écriture que la session retrouvée doit avoir (`HttpAuthRepository.hasWriteToken`). Un cookie
 * vide ou indécodable compte pour absent (#748, J).
 */
export function hasCsrfToken(): boolean {
  const token = readCsrfToken()

  return null !== token && '' !== token
}
