import type { Router } from 'vue-router'
import { markSessionExpired } from '../../application/auth/useAuth'
import { isSupportedLocale, type Locale } from '../../domain/portfolio/entities/Locale'
import { resolvePreferredLocale } from './preferredLocale'

/**
 * La réaction du backoffice à un 401 (#650 R1c), branchée dans `main.ts` sur
 * `setBackofficeUnauthorizedHandler` : la session est finie (JWT expiré, ou
 * ouverte avant un changement de mot de passe, R1b). L'en-tête repasse à
 * « anonyme » et la personne est menée à la connexion, qui la ramènera à la page
 * où elle se trouvait (`redirect`, jugé par LoginPage : chemin interne seulement).
 *
 * `replace` plutôt que `push` : le retour arrière ne ramène pas à une page dont
 * l'API refuse tout. Une page du backoffice charge plusieurs listes en
 * parallèle, et chacune en 401 appelle cette réaction : seule la première
 * navigue, sans quoi la suivante écraserait le `redirect` par la page de
 * connexion elle-même.
 */
export function createBackofficeSessionExpiryHandler(router: Router): () => void {
  let isRedirecting = false

  return () => {
    markSessionExpired()

    const current = router.currentRoute.value
    if (isRedirecting || 'login' === current.name) {
      return
    }

    isRedirecting = true
    const routeLocale = current.params.locale
    const locale: Locale = 'string' === typeof routeLocale && isSupportedLocale(routeLocale) ? routeLocale : resolvePreferredLocale()

    void router
      .replace({ name: 'login', params: { locale }, query: { redirect: current.fullPath } })
      .finally(() => {
        isRedirecting = false
      })
  }
}
