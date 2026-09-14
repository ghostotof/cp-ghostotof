import { ref, type Ref } from 'vue'

export interface UseRowDragAndDropResult {
  /** Index de la ligne en cours de glissement, pour son style (`opacity`…) ; `null` hors glissement. */
  draggingIndex: Ref<number | null>
  onDragStart: (index: number) => void
  onDragOver: (event: DragEvent) => void
  onDrop: (index: number) => void
  onDragEnd: () => void
}

/**
 * Glisser-déposer HTML5 natif d'une ligne de tableau (D7, sans dépendance).
 * La ligne glissée est gardée dans l'état du composable, jamais dans
 * `event.dataTransfer` : jsdom n'implémente pas `DataTransfer`, donc un flux
 * qui en dépendrait ne serait testable que dans un vrai navigateur. `move`
 * (typiquement `useOrderDraft().move`) est appelé une fois, au `drop`.
 */
export function useRowDragAndDrop(move: (from: number, to: number) => void): UseRowDragAndDropResult {
  const draggingIndex = ref<number | null>(null)

  function onDragStart(index: number): void {
    draggingIndex.value = index
  }

  function onDragOver(event: DragEvent): void {
    // Un `dragover` non empêché refuse le `drop` — c'est le comportement par
    // défaut du navigateur pour la plupart des éléments.
    event.preventDefault()

    if (event.dataTransfer) {
      event.dataTransfer.effectAllowed = 'move'
    }
  }

  function onDrop(index: number): void {
    if (null !== draggingIndex.value && draggingIndex.value !== index) {
      move(draggingIndex.value, index)
    }

    draggingIndex.value = null
  }

  function onDragEnd(): void {
    draggingIndex.value = null
  }

  return { draggingIndex, onDragStart, onDragOver, onDrop, onDragEnd }
}
