import { readWriteToken } from '../auth/csrfCookie'

/**
 * La règle unique qui décide, côté frontend, qu'une réponse signifie « la session est finie » (#748,
 * constat 15) : un 401, ou un 403 à une écriture **envoyée sans jeton CSRF**. Référence de toute la
 * règle (backoffice, connexions MCP, consentement OAuth, assistant) : `.claude/rules/frontend-session.md`.
 *
 * Les faits, vérifiés dans le backend, qui la fondent :
 * - `CsrfCookieRequestSubscriber` écoute `kernel.request` en priorité 20, avant le pare-feu (8), et ne
 *   contrôle que `POST`, `PUT`, `PATCH` et `DELETE` sous `/api` : une lecture n'est jamais refusée pour
 *   le CSRF, et une écriture sans en-tête `X-XSRF-TOKEN` (ou sans cookie) reçoit un 403 avant toute
 *   authentification ;
 * - `XSRF-TOKEN` n'est pas `HttpOnly` (seul `BEARER` l'est, `AuthCookieFactory`) : le client le lit
 *   (`infrastructure/auth/csrfCookie.ts`) et n'envoie l'en-tête que s'il en trouve un ;
 * - depuis R21 (#748, F1), `BEARER` et `XSRF-TOKEN` sont deux cookies de session (ni `Expires` ni
 *   `Max-Age`), au login comme à l'accès de base : le navigateur fermé, sans restauration de session,
 *   finit la session. Le JWT garde son `exp` ; à son expiration la première requête prend un 401 qui
 *   efface les deux cookies (`StaleSessionCookiesClearingListener`) — une lecture : 401 ; une écriture :
 *   401, puis, cookies effacés, le 403 sans jeton du CSRF. Une session ouverte avant le déploiement garde
 *   ses cookies persistants, une heure au plus ;
 * - `/api/logout` (`CookieLogoutListener`) expire lui aussi les deux cookies : même verdict ;
 *
 * Un 403 à une écriture qui portait son jeton, ou à une lecture, est un refus réel (autorisation,
 * signature CSRF invalide) et garde son sens. Le signal est « le client n'avait pas de jeton à envoyer »,
 * connu **à l'envoi** : pas une relecture du cookie à la réception, et pas le texte du 403, localisable
 * et sans valeur de contrat. Un cookie présent mais vide n'envoie pas d'en-tête : c'est « sans jeton ».
 *
 * Limite acceptée (S5) : entre la lecture du cookie et l'envoi, un autre onglet peut poser ou retirer
 * `XSRF-TOKEN`. Le verdict reste juste pour la requête partie (elle a, ou non, porté un jeton) ; au pire,
 * un onglet qui vient de se reconnecter lit son 403 en vol comme une fin de session, et la revérification
 * au retour sur l'onglet la rétablit.
 */
export function endsTheSession(status: number, sentWithoutCsrfToken: boolean): boolean {
  return 401 === status || (403 === status && sentWithoutCsrfToken)
}

/** La réponse, et le verdict « session finie » pris à l'envoi. */
export interface SessionAwareResponse {
  readonly response: Response
  /** Vrai pour un 401, ou un 403 à une écriture partie sans jeton CSRF (`endsTheSession`). */
  readonly endedTheSession: boolean
}

/**
 * Ce que `sessionAwareFetch` accepte : un `RequestInit` sans `credentials` ni `headers` libres (l'envoi
 * pose les cookies et l'en-tête CSRF), avec une méthode obligatoire et des en-têtes en simple dictionnaire.
 * Le type de tous les clients qui lui passent un `init` (backoffice, connexions MCP, consentement).
 */
export type SessionAwareInit = Omit<RequestInit, 'credentials' | 'headers'> & {
  method: string
  headers?: Readonly<Record<string, string>>
}

/** Les méthodes que le CSRF ne contrôle pas. */
const SAFE_METHODS: readonly string[] = ['GET', 'HEAD']

/**
 * L'unique envoi du frontend qui juge la session : cookies (`credentials: 'include'`), en-tête
 * `X-XSRF-TOKEN` pour une écriture (double-submit cookie), et le verdict `endedTheSession` pris sur ce
 * qui est réellement parti. Les clients (backoffice, connexions MCP, consentement, assistant) l'appellent,
 * puis jugent `endedTheSession` à une place fixe de leur traduction d'erreur ; aucun ne passe plus un
 * booléen « sans jeton » de fonction en fonction. C'est une fonction partagée, pas un client : la
 * réaction globale du backoffice (`BackofficeHttpClient`) n'est pas celle des trois autres, qui marquent
 * la session dans leur composable.
 *
 * Un rejet de `fetch` (réseau, `AbortError`) remonte tel quel : c'est au client de le traduire.
 */
export async function sessionAwareFetch(url: string, init: SessionAwareInit): Promise<SessionAwareResponse> {
  const isWrite = !SAFE_METHODS.includes(init.method.toUpperCase())
  // Le cookie est lu **une seule fois** (R28, CR3-10) : le verdict « avec jeton » et l'en-tête sont dérivés de la
  // même valeur, donc ne peuvent pas diverger même si un autre onglet retire `XSRF-TOKEN` entre deux lectures.
  // « Avec jeton » reste la définition de `csrfCookie.ts` (valeur décodée et non vide), que `readWriteToken` applique.
  const csrfToken = isWrite ? readWriteToken() : null

  const headers = { ...init.headers, ...(csrfToken ? { 'X-XSRF-TOKEN': csrfToken } : {}) }

  // Pas de clé `headers` quand il n'y en a aucun : une lecture nue part comme elle partait avant.
  const response = await fetch(url, {
    ...init,
    credentials: 'include',
    ...(Object.keys(headers).length > 0 ? { headers } : {}),
  })

  return { response, endedTheSession: endsTheSession(response.status, isWrite && null === csrfToken) }
}
