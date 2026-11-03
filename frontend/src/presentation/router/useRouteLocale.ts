import { computed, type ComputedRef } from 'vue'
import { useRoute } from 'vue-router'
import { useCurrentLocale } from '../../application/shared/useCurrentLocale'
import type { Locale } from '../../domain/portfolio/entities/Locale'
import { routeLocale } from './routeLocale'

/**
 * La langue de la page affichée (#748, R227, St18-12, St18-13) : celle de la route, sinon la langue d'interface
 * (`useCurrentLocale()`, `fr` quand vue-i18n porte une langue que le site n'a pas). C'est la langue d'une adresse que la
 * page construit (`/${pageLocale}/login`).
 *
 * Un seul calcul pour les composants qui la lisent, plutôt qu'un `routeLocale(route, interfaceLocale.value)` recopié dans
 * chacun : le repli ne sert qu'à la page 404, sans segment `:locale`. À appeler dans un `setup`.
 */
export function useRouteLocale(): ComputedRef<Locale> {
  const route = useRoute()
  const interfaceLocale = useCurrentLocale()

  return computed(() => routeLocale(route, interfaceLocale.value))
}
