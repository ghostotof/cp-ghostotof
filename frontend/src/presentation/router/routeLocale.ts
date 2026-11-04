import { isSupportedLocale, type Locale } from '../../domain/portfolio/entities/Locale'

/**
 * La langue d'une route : son paramètre `locale` s'il est pris en charge, sinon `fallback` (#748, R6, Std7).
 *
 * La route est la source de vérité de la langue (le routeur synchronise i18n sur elle à chaque navigation) ;
 * le repli — la langue i18n courante, que l'appelant passe — ne sert qu'aux routes sans segment `:locale`
 * (la page 404, `/:pathMatch(.*)*`). Avant cette fonction, l'en-tête lisait la route et les deux autres sites
 * du lien de reconnexion lisaient i18n : les deux ne peuvent différer qu'un instant, pendant une navigation,
 * mais un seul calcul évite d'avoir à le démontrer.
 */
export function routeLocale(route: { params: Readonly<Record<string, unknown>> }, fallback: Locale): Locale {
  const fromRoute = route.params.locale
  return 'string' === typeof fromRoute && isSupportedLocale(fromRoute) ? fromRoute : fallback
}
