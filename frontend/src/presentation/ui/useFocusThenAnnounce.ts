import { onBeforeUnmount, readonly, shallowRef, type Ref } from 'vue'

/** Délai entre le déplacement du focus et l'écriture de l'annonce dans la région d'état (#748). */
export const ANNOUNCE_AFTER_FOCUS_MS = 250

export interface FocusThenAnnounce<T> {
  /** L'issue que la région d'état doit dire : copie différée de ce qui a été demandé, vide tant que le focus n'a pas bougé. */
  announced: Readonly<Ref<T | null>>
  /**
   * Annule l'annonce en cours (et efface celle déjà écrite), pose le focus sur `target` tout de
   * suite, puis écrit `value` dans `announced` après le délai.
   */
  focusThenAnnounce: (target: HTMLElement | null | undefined, value: T) => void
  /** Annule la minuterie et vide `announced` : un geste qui relance une lecture ne laisse pas l'issue précédente. */
  cancel: () => void
  /** Vrai une fois le composant démonté : une opération asynchrone qui aboutit après n'écrit plus rien. */
  isUnmounted: () => boolean
}

/**
 * « Focus puis annonce » (#748, `.claude/rules/frontend-a11y.md`) : quand un geste déplace le focus
 * ET annonce une issue, le focus part d'abord et l'annonce est écrite ensuite — une annonce écrite
 * au moment du déplacement peut être coupée par la lecture de l'élément qui prend le focus.
 *
 * Écarté : `aria-describedby` de la cible vers le message, dont la lecture au focus dépend des
 * réglages du lecteur d'écran et qui resterait collé à la cible à chaque focus suivant.
 *
 * Le composable tient la minuterie, son annulation et le démontage : la page ne connaît que
 * `announced` (à lire dans sa région `role="status"`), `focusThenAnnounce` et `cancel`.
 */
export function useFocusThenAnnounce<T>(delayMs: number = ANNOUNCE_AFTER_FOCUS_MS): FocusThenAnnounce<T> {
  const announced = shallowRef<T | null>(null)
  let timer: ReturnType<typeof setTimeout> | null = null
  let unmounted = false

  const cancel = (): void => {
    if (null !== timer) {
      clearTimeout(timer)
      timer = null
    }
    announced.value = null
  }

  const focusThenAnnounce = (target: HTMLElement | null | undefined, value: T): void => {
    cancel()
    target?.focus()
    timer = setTimeout(() => {
      timer = null
      announced.value = value
    }, delayMs)
  }

  onBeforeUnmount(() => {
    unmounted = true
    cancel()
  })

  return { announced: readonly(announced) as Readonly<Ref<T | null>>, focusThenAnnounce, cancel, isUnmounted: () => unmounted }
}
