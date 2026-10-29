import type { RouteLocationNormalizedLoaded, RouteLocationRaw } from 'vue-router'
import type { Locale } from '../../domain/portfolio/entities/Locale'
import { REAUTH_QUERY, REAUTH_VALUE } from './reauthentication'
import { routeLocale } from './routeLocale'
import { SESSION_ENDED_QUERY, SESSION_ENDED_VALUE } from './sessionEnded'

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
 * Le drapeau est lu à l'appel, dans le `switch`, et non dans une table du module : `sessionEnded.ts` et
 * `reauthentication.ts` appellent ce module, qui importe leurs constantes — un cycle d'import sans effet tant que
 * rien n'est évalué au chargement.
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
