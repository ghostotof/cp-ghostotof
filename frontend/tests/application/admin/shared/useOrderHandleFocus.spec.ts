import { afterEach, describe, expect, it, vi } from 'vitest'
import { useOrderHandleFocus } from '../../../../src/application/admin/shared/useOrderHandleFocus'

const GROUP_ONE = '019968b0-0000-7000-8000-000000000101'
const GROUP_TWO = '019968b0-0000-7000-8000-000000000102'

/** Une cellule de tableau telle que la page la rend : `data-order-key` + la poignée. */
function createHandleCell(key: string): { cell: HTMLElement; button: HTMLButtonElement } {
  const cell = document.createElement('td')
  cell.dataset.orderKey = key
  const button = document.createElement('button')
  cell.appendChild(button)
  document.body.appendChild(cell)

  return { cell, button }
}

describe('useOrderHandleFocus', () => {
  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('déplace la ligne puis rend le focus à la poignée de cette ligne', async () => {
    const move = vi.fn()
    const { registerHandleCell, moveRow } = useOrderHandleFocus(move)
    const { cell, button } = createHandleCell(GROUP_ONE)

    registerHandleCell(cell)
    await moveRow(GROUP_ONE, 0, 1)

    expect(move).toHaveBeenCalledWith(0, 1)
    expect(document.activeElement).toBe(button)
  })

  it("déplace quand même une ligne dont la cellule n'a pas été enregistrée", async () => {
    const move = vi.fn()
    const { moveRow } = useOrderHandleFocus(move)

    await moveRow(GROUP_TWO, 1, 0)

    // Le déplacement est la fonction ; le focus n'en est que le confort.
    expect(move).toHaveBeenCalledWith(1, 0)
  })

  it('ignore un élément sans clé de ligne', async () => {
    const move = vi.fn()
    const { registerHandleCell, moveRow } = useOrderHandleFocus(move)
    const orphan = document.createElement('td')
    const button = document.createElement('button')
    orphan.appendChild(button)
    document.body.appendChild(orphan)

    registerHandleCell(orphan)
    await moveRow(GROUP_ONE, 0, 1)

    expect(document.activeElement).not.toBe(button)
  })

  it('purge les cellules détachées quand Vue rappelle la `ref` avec null', async () => {
    const move = vi.fn()
    const { registerHandleCell, moveRow } = useOrderHandleFocus(move)
    const first = createHandleCell(GROUP_ONE)
    const second = createHandleCell(GROUP_TWO)
    registerHandleCell(first.cell)
    registerHandleCell(second.cell)

    // La ligne est supprimée du tableau : Vue rappelle la fonction de `ref`
    // avec `null`, sans dire laquelle. Sans purge, la cellule et tout son
    // sous-arbre resteraient retenus par la carte jusqu'au démontage de la page.
    first.cell.remove()
    registerHandleCell(null)

    const focusSpy = vi.spyOn(first.button, 'focus')
    await moveRow(GROUP_ONE, 0, 1)
    expect(focusSpy).not.toHaveBeenCalled()

    // La ligne toujours montée, elle, reste enregistrée.
    await moveRow(GROUP_TWO, 1, 0)
    expect(document.activeElement).toBe(second.button)
  })
})
