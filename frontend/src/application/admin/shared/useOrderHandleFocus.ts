import { nextTick, type ComponentPublicInstance } from 'vue'

export interface UseOrderHandleFocusResult {
  /**
   * `ref` de la cellule qui porte la poignée. Fonction **stable** (jamais une
   * lambda inline dans le template, qui serait rappelée avec `null` puis
   * l'élément à chaque rendu et laisserait la carte dans un état dépendant de
   * l'ordre des appels) : la clé de ligne est lue sur le DOM,
   * `data-order-key`.
   */
  registerHandleCell: (el: Element | ComponentPublicInstance | null) => void
  /** Déplace la ligne puis rend le focus à sa poignée, une fois le DOM à jour. */
  moveRow: (key: string, from: number, to: number) => Promise<void>
}

/**
 * Après un déplacement au clavier, le focus doit revenir sur la poignée de la
 * ligne déplacée : sans cela l'utilisateur clavier perd sa place au premier
 * appui sur ↓, la ligne ayant changé de rang dans le DOM (spec 0004, D7).
 *
 * Une instance par tableau ordonné — la page Qualité en compte deux (principes
 * et traits), ce qui est précisément pourquoi cette mécanique est un composable
 * partagé et non quinze lignes recopiées dans chaque page.
 */
export function useOrderHandleFocus(move: (from: number, to: number) => void): UseOrderHandleFocusResult {
  const handleCells = new Map<string, HTMLElement>()

  function registerHandleCell(el: Element | ComponentPublicInstance | null): void {
    if (!(el instanceof HTMLElement)) {
      return
    }

    const key = el.dataset.orderKey
    if (undefined !== key) {
      handleCells.set(key, el)
    }
  }

  async function moveRow(key: string, from: number, to: number): Promise<void> {
    move(from, to)
    await nextTick()
    handleCells.get(key)?.querySelector('button')?.focus()
  }

  return { registerHandleCell, moveRow }
}
