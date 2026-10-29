const CSRF_COOKIE_NAME = 'XSRF-TOKEN'

/**
 * Lit le cookie XSRF-TOKEN posé par le backend au login (non httpOnly, contrairement au JWT) et rend le jeton
 * **qu'une écriture peut porter** : sa valeur décodée, renvoyée telle quelle dans le header X-XSRF-TOKEN
 * (pattern double-submit cookie, cf. backend App\Security\Jwt\CsrfCookieRequestSubscriber), ou `null`.
 *
 * Un cookie vide, ou dont la valeur ne se décode pas (`%E0`), vaut **absent** : le serveur n'émet que des jetons
 * signés, sans caractère à encoder, donc une telle valeur n'est jamais la sienne. La rendre telle quelle n'aiderait
 * pas (le serveur, qui décode le cookie, la refuserait aussi) ; la laisser lever `URIError` ferait échouer chaque
 * écriture avant tout envoi (#748, F13). Un cookie vide n'envoie aucun en-tête : c'est « sans jeton » (J).
 *
 * **Le cookie est lu une seule fois par envoi** (R28, CR3-10) : l'appelant qui juge la session (`sessionAwareFetch`)
 * dérive le verdict et l'en-tête de **cette** valeur, jamais de deux lectures qu'un autre onglet pourrait séparer.
 */
export function readWriteToken(): string | null {
  const match = document.cookie
    .split('; ')
    .find((entry) => entry.startsWith(`${CSRF_COOKIE_NAME}=`))

  if (undefined === match) {
    return null
  }

  try {
    const token = decodeURIComponent(match.slice(CSRF_COOKIE_NAME.length + 1))

    return '' === token ? null : token
  } catch (error) {
    if (error instanceof URIError) {
      return null
    }

    throw error
  }
}

/**
 * « Avec jeton » : la seule définition, `null !== readWriteToken()`, pour le jeton d'écriture que la session
 * retrouvée doit avoir (`HttpAuthRepository.hasWriteToken`). `sessionAwareFetch`, qui a besoin de la valeur et
 * du verdict d'une même lecture, applique la même définition en lisant `readWriteToken()` une fois.
 */
export function hasCsrfToken(): boolean {
  return null !== readWriteToken()
}
