import { onBeforeUnmount, watch, type Ref } from 'vue'

/**
 * Publie la hauteur d'un élément en propriété CSS de `<html>` (#748, constat 7).
 *
 * L'en-tête est collant (`sticky-top`) et le bandeau de session finie doit se coller **juste dessous** :
 * `top: var(--app-header-height)`. La hauteur de l'en-tête n'est pas une constante — elle change avec le
 * menu mobile ouvert, une alerte de l'en-tête, un libellé qui passe à la ligne — d'où une mesure vivante
 * (`ResizeObserver`) plutôt qu'une valeur codée en dur, qui laisserait le bandeau sous l'en-tête ou
 * décollé de lui.
 *
 * - La propriété est posée tant que la cible existe et **retirée** quand elle disparaît (`v-if`) et au
 *   démontage : un bandeau absent ne doit pas réserver de place (`scroll-padding-top`).
 * - `borderBoxSize` (bordure comprise, fractionnaire) arrondi au pixel **supérieur** : arrondir en dessous
 *   laisserait un filet de contenu entre l'en-tête et le bandeau.
 * - Sans `ResizeObserver` (jsdom, très vieux navigateur), ne fait rien : la propriété reste absente et les
 *   `var(…, 0px)` retombent sur zéro — un défaut de collage, jamais une page cassée.
 *
 * @param target   élément mesuré (`null` tant qu'il n'est pas rendu)
 * @param property nom de la propriété CSS posée sur `document.documentElement`
 */
export function usePublishedHeight(target: Readonly<Ref<HTMLElement | null>>, property: `--${string}`): void {
  if (typeof ResizeObserver === 'undefined') {
    return
  }

  const root = document.documentElement
  let observer: ResizeObserver | null = null

  function stopObserving(): void {
    observer?.disconnect()
    observer = null
    root.style.removeProperty(property)
  }

  watch(
    target,
    (element) => {
      stopObserving()

      if (null === element) {
        return
      }

      observer = new ResizeObserver((entries) => {
        const entry = entries[entries.length - 1]
        // `borderBoxSize` est absent des navigateurs les plus anciens qui ont un ResizeObserver.
        const height = entry.borderBoxSize?.[0]?.blockSize ?? element.getBoundingClientRect().height
        root.style.setProperty(property, `${Math.ceil(height)}px`)
      })
      observer.observe(element)
    },
    { immediate: true },
  )

  onBeforeUnmount(stopObserving)
}
