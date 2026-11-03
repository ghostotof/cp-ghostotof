import { computed, type ComputedRef } from 'vue'
import { useI18n } from 'vue-i18n'
import { DEFAULT_LOCALE, isSupportedLocale, type Locale } from '../../domain/portfolio/entities/Locale'

/**
 * La langue courante de l'interface, typée `Locale`.
 *
 * `useI18n().locale` est une `string` : vue-i18n accepte n'importe quelle valeur, et une extension ou un test peut en poser
 * une que le site ne porte pas (`de`). Une assertion (`locale.value as Locale`) ne vérifie rien et laisserait cette valeur
 * atteindre une adresse (`/de/login`) ou un appel d'API. Ici la valeur est **jugée** (`isSupportedLocale`) et, hors du
 * site, remplacée par la langue par défaut ; la garde de source de `frontend.md` (« i18n ») interdit l'assertion et toute
 * autre lecture de cette langue.
 *
 * Le composable vit dans `application/shared/` parce que les composables de contenu en ont besoin et que `presentation/`
 * peut importer `application/`, non l'inverse. À appeler dans un `setup`, comme `useI18n()`.
 *
 * Écarté : le générique de langues de vue-i18n (`useI18n<…, Locale>()`), qui type sans rien vérifier à l'exécution et
 * qu'il faudrait écrire à chaque appel.
 */
export function useCurrentLocale(): ComputedRef<Locale> {
  const { locale } = useI18n()

  return computed(() => (isSupportedLocale(locale.value) ? locale.value : DEFAULT_LOCALE))
}
