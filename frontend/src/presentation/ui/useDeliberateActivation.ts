import { onBeforeUnmount, onMounted, readonly, ref, type Ref } from 'vue'

/**
 * Le temps pendant lequel la page doit avoir été visible **et** active avant
 * que ses boutons de décision s'activent (#647, F8 de la relecture). Assez
 * long pour qu'un second clic déjà lancé ne tombe pas dessus, assez court
 * pour qu'une personne qui lit la page ne l'attende jamais.
 */
export const ACTIVATION_DELAY_MS = 600

export interface DeliberateActivation {
  /** Vrai une fois la page visible et active depuis ACTIVATION_DELAY_MS. */
  isArmed: Readonly<Ref<boolean>>
  /**
   * Enveloppe une action de clic : elle ne part que si les boutons sont actifs
   * et que l'appui du pointeur, s'il y en a eu un, a commencé **après** leur
   * activation.
   */
  guard: (action: () => unknown) => (event: MouseEvent) => void
}

/**
 * Protection d'une page de décision contre le double-clickjacking : une page
 * tierce fait double-cliquer la personne, et entre les deux clics fait passer
 * devant elle une fenêtre ouverte sur la page à protéger, dont le bouton reçoit
 * le second clic sans qu'elle l'ait vu.
 *
 * Deux règles, qui se complètent :
 * - **activation différée** : les boutons ne s'activent qu'après
 *   ACTIVATION_DELAY_MS de page visible (`document.visibilityState`) et active
 *   (`document.hasFocus()`) ; une page cachée ou qui perd le focus les
 *   désactive, et le délai repart à son retour ;
 * - **appui antérieur ignoré** : un clic dont l'appui (`pointerdown`) a eu lieu
 *   avant l'activation ne compte pas — le second clic d'un double-clic posé
 *   pendant le délai, relâché juste après. Un clic au clavier n'a pas d'appui
 *   de pointeur (`detail` à 0) : il ne dépend que de l'activation.
 *
 * Les en-têtes `frame-ancestors`/`X-Frame-Options` couvrent déjà le
 * clickjacking par cadre ; ceci couvre la fenêtre, qu'aucun en-tête n'atteint.
 */
export function useDeliberateActivation(delayMs: number = ACTIVATION_DELAY_MS): DeliberateActivation {
  const isArmed = ref(false)
  let timer: ReturnType<typeof setTimeout> | null = null
  /** L'appui du pointeur le plus récent a-t-il commencé avant l'activation ? */
  let pressStartedBeforeActivation = false

  const clearTimer = (): void => {
    if (null !== timer) {
      clearTimeout(timer)
      timer = null
    }
  }

  const isVisibleAndActive = (): boolean => 'visible' === document.visibilityState && document.hasFocus()

  const disarm = (): void => {
    clearTimer()
    isArmed.value = false
  }

  /** Le délai repart de zéro, si la page est visible et active ; sinon tout reste inactif. */
  const restart = (): void => {
    disarm()
    if (isVisibleAndActive()) {
      timer = setTimeout(() => {
        timer = null
        isArmed.value = true
      }, delayMs)
    }
  }

  const onVisibilityChange = (): void => restart()
  const onPointerDown = (): void => {
    pressStartedBeforeActivation = !isArmed.value
  }

  onMounted(() => {
    document.addEventListener('visibilitychange', onVisibilityChange)
    // En capture : l'appui est noté avant tout gestionnaire, y compris sur un
    // bouton encore désactivé.
    document.addEventListener('pointerdown', onPointerDown, true)
    window.addEventListener('focus', restart)
    window.addEventListener('blur', disarm)
    restart()
  })

  onBeforeUnmount(() => {
    document.removeEventListener('visibilitychange', onVisibilityChange)
    document.removeEventListener('pointerdown', onPointerDown, true)
    window.removeEventListener('focus', restart)
    window.removeEventListener('blur', disarm)
    clearTimer()
  })

  const guard =
    (action: () => unknown) =>
    (event: MouseEvent): void => {
      const pointerClick = event.detail > 0
      const pressedTooEarly = pressStartedBeforeActivation
      pressStartedBeforeActivation = false

      if (!isArmed.value || (pointerClick && pressedTooEarly)) {
        return
      }
      void action()
    }

  return { isArmed: readonly(isArmed), guard }
}
