/**
 * La règle unique qui décide, côté frontend, qu'une réponse du backoffice signifie « la session est
 * finie » (#748, constat 15) : un 401, ou un 403 à une écriture **envoyée sans jeton CSRF**.
 *
 * Quatre faits, vérifiés dans le backend, la fondent :
 * - `CsrfCookieRequestSubscriber` écoute `kernel.request` en priorité 20, avant le pare-feu (8), et ne
 *   contrôle que `POST`, `PUT`, `PATCH` et `DELETE` sous `/api` : une lecture n'est jamais refusée pour
 *   le CSRF, et une écriture sans en-tête `X-XSRF-TOKEN` (ou sans cookie) reçoit un 403 avant toute
 *   authentification ;
 * - `XSRF-TOKEN` n'est pas `HttpOnly` (seul `BEARER` l'est, `AuthCookieFactory`) : le client le lit
 *   (`infrastructure/auth/csrfCookie.ts`) et n'envoie l'en-tête que s'il en trouve un ;
 * - au login, `XSRF-TOKEN` est un cookie de session (`LoginSuccessSubscriber`) alors que `BEARER` vit
 *   3 600 s : à l'expiration du JWT, le navigateur jette `BEARER` et garde `XSRF-TOKEN`, donc une
 *   écriture passe le CSRF et prend le 401 du pare-feu ;
 * - `/api/logout` (`CookieLogoutListener`) et le refus d'une session antérieure au changement de mot de
 *   passe (`StaleSessionCookiesClearingListener`) expirent les deux cookies : après eux, une lecture
 *   prend un 401, mais une écriture part sans jeton et prend un 403 du CSRF.
 *
 * Un 403 à une écriture qui portait son jeton, ou à une lecture, est un refus réel (autorisation,
 * signature CSRF invalide) et garde son sens. Le signal est « le client n'avait pas de jeton à envoyer »,
 * connu à l'envoi : pas une relecture du cookie à la réception (un autre onglet peut l'avoir reposé
 * entre-temps), et pas le texte du 403, localisable et sans valeur de contrat.
 *
 * @param status                le statut HTTP de la réponse.
 * @param sentWithoutCsrfToken  vrai si la requête est une écriture partie sans en-tête `X-XSRF-TOKEN` ;
 *                              toujours faux pour une lecture, que le CSRF ne contrôle pas.
 */
export function endsTheSession(status: number, sentWithoutCsrfToken: boolean): boolean {
  return 401 === status || (403 === status && sentWithoutCsrfToken)
}
