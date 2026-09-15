import { nextTick, ref, type ComponentPublicInstance, type Ref } from 'vue'

/** Le dernier déplacement clavier, en position humaine (1-based), pour l'annonce. */
export interface OrderMove {
  position: number
  count: number
}

export interface UseOrderHandleFocusResult {
  /**
   * `ref` de la cellule qui porte la poignée. Fonction **stable** (jamais une
   * lambda inline dans le template, qui serait rappelée avec `null` puis
   * l'élément à chaque rendu et laisserait la carte dans un état dépendant de
   * l'ordre des appels) : la clé de ligne est lue sur le DOM,
   * `data-order-key`. Appelée avec `null` au démontage d'une ligne, elle
   * **purge** les cellules détachées au lieu de les garder.
   */
  registerHandleCell: (el: Element | ComponentPublicInstance | null) => void
  /** Déplace la ligne puis rend le focus à sa poignée, une fois le DOM à jour. */
  moveRow: (key: string, from: number, to: number) => Promise<void>
  /**
   * Le dernier déplacement, que `OrderToolbar` annonce dans la région live du
   * tableau (#170 F3) — hors de la ligne déplacée, dont le re-parentage au
   * même cycle de rendu pouvait faire avaler l'annonce. `null` avant le
   * premier déplacement.
   */
  lastMove: Ref<OrderMove | null>
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
export function useOrderHandleFocus(
  move: (from: number, to: number) => void,
  count: () => number,
): UseOrderHandleFocusResult {
  const handleCells = new Map<string, HTMLElement>()
  const lastMove = ref<OrderMove | null>(null)

  function registerHandleCell(el: Element | ComponentPublicInstance | null): void {
    if (!(el instanceof HTMLElement)) {
      // Vue appelle la fonction de `ref` avec `null` quand une ligne est
      // démontée, sans dire laquelle : la carte est donc purgée de ses cellules
      // qui ne sont plus dans le document — exactement l'ensemble des lignes
      // disparues. Sans cela, supprimer une entrée laisserait sa cellule (et
      // tout son sous-arbre DOM) retenue jusqu'au démontage de la page.
      for (const [knownKey, cell] of handleCells) {
        if (!cell.isConnected) {
          handleCells.delete(knownKey)
        }
      }

      return
    }

    const key = el.dataset.orderKey
    if (undefined !== key) {
      handleCells.set(key, el)
    }
  }

  async function moveRow(key: string, from: number, to: number): Promise<void> {
    move(from, to)
    lastMove.value = { position: to + 1, count: count() }
    await nextTick()
    handleCells.get(key)?.querySelector('button')?.focus()
  }

  return { registerHandleCell, moveRow, lastMove }
}
