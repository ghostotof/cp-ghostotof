import type { RouteLocationNormalizedLoaded, RouteLocationRaw } from 'vue-router'
import type { Locale } from '../../domain/portfolio/entities/Locale'
import { routeLocale } from './routeLocale'

/**
 * Les deux drapeaux de la requête de la connexion, déclarés une fois. `expired=1` : la session a pris fin (lu par
 * `isSessionEnded`, `sessionEnded.ts`) ; `reauth=1` : la personne rouvre la connexion exprès (lu par
 * `isReauthentication`, `reauthentication.ts`). Une seule valeur, `1` : tout autre ne compte pas. Rien d'autre ne les
 * nomme (`signInLocations.spec.ts`).
 */
export const SESSION_ENDED_QUERY = 'expired'
export const SESSION_ENDED_VALUE = '1'
export const REAUTH_QUERY = 'reauth'
export const REAUTH_VALUE = '1'

/**
 * Pourquoi la connexion s'ouvre (#748, R16, I) :
 * - `session-ended` : la session de la personne a pris fin (`expired=1`, `signInAgainLocation`) ;
 * - `reauthentication` : la personne est connectée et rouvre la connexion exprès (`reauth=1`,
 *   `switchAccountLocation`).
 */
export type LoginFlag = 'session-ended' | 'reauthentication'

/**
 * Le seul constructeur d'un lien vers la connexion (#748, R16, I) : `signInAgainLocation` et `switchAccountLocation`
 * ne font que choisir le drapeau. La connexion dans la langue de la route (`fallback`, la langue i18n, quand elle
 * n'en a pas), le drapeau, et le retour à la page courante.
 *
 * **`redirect` n'est posé que sur une route `requiresAuth`** (F8) : seules ces routes passent par
 * `protectedRoutesCarryNoSecret` (aucune ne porte de secret dans sa requête ni son fragment), donc seul leur chemin
 * complet peut partir dans une URL que le serveur web journalise. Une autre route (publique, 404) n'a pas été revue :
 * le lien n'a pas de retour, et la connexion ramène à l'accueil.
 *
 * Les deux drapeaux sont déclarés ici (St3-9) : `sessionEnded.ts` et `reauthentication.ts` appellent ce module et
 * importent leurs constantes de lui, jamais l'inverse — aucun cycle d'import.
 */
export function loginLocation(
  route: Pick<RouteLocationNormalizedLoaded, 'fullPath' | 'params' | 'meta'>,
  fallback: Locale,
  flag: LoginFlag,
): RouteLocationRaw {
  const flagQuery: Record<string, string> =
    'session-ended' === flag ? { [SESSION_ENDED_QUERY]: SESSION_ENDED_VALUE } : { [REAUTH_QUERY]: REAUTH_VALUE }

  return {
    name: 'login',
    params: { locale: routeLocale(route, fallback) },
    query: true === route.meta.requiresAuth ? { redirect: route.fullPath, ...flagQuery } : flagQuery,
  }
}
