import { describe, expect, it, vi } from 'vitest'
import { useRowDragAndDrop } from '../../../../src/application/admin/shared/useRowDragAndDrop'

function dragEvent(dataTransfer: Partial<DataTransfer> | null = null): DragEvent {
  return { preventDefault: vi.fn(), dataTransfer } as unknown as DragEvent
}

describe('useRowDragAndDrop', () => {
  it('dragstart puis drop déplace la ligne', () => {
    const move = vi.fn()
    const { onDragStart, onDrop, draggingIndex } = useRowDragAndDrop(move)

    onDragStart(0)
    expect(draggingIndex.value).toBe(0)

    onDrop(2)

    expect(move).toHaveBeenCalledWith(0, 2)
    expect(draggingIndex.value).toBeNull()
  })

  it('drop sans dragstart ne fait rien', () => {
    const move = vi.fn()
    const { onDrop, draggingIndex } = useRowDragAndDrop(move)

    onDrop(1)

    expect(move).not.toHaveBeenCalled()
    expect(draggingIndex.value).toBeNull()
  })

  it('un drop sur la ligne déjà en train de glisser ne fait rien', () => {
    const move = vi.fn()
    const { onDragStart, onDrop } = useRowDragAndDrop(move)

    onDragStart(1)
    onDrop(1)

    expect(move).not.toHaveBeenCalled()
  })

  it("dragend efface l'index en cours sans déplacer", () => {
    const move = vi.fn()
    const { onDragStart, onDragEnd, draggingIndex } = useRowDragAndDrop(move)

    onDragStart(0)
    onDragEnd()

    expect(draggingIndex.value).toBeNull()
    expect(move).not.toHaveBeenCalled()
  })

  it('dragover empêche le comportement par défaut, sans dataTransfer (jsdom)', () => {
    const move = vi.fn()
    const { onDragOver } = useRowDragAndDrop(move)
    const event = dragEvent(null)

    expect(() => onDragOver(event)).not.toThrow()
    expect(event.preventDefault).toHaveBeenCalled()
  })

  it('dragover renseigne effectAllowed quand dataTransfer est présent', () => {
    const move = vi.fn()
    const { onDragOver } = useRowDragAndDrop(move)
    const dataTransfer: Partial<DataTransfer> = {}
    const event = dragEvent(dataTransfer)

    onDragOver(event)

    expect(dataTransfer.effectAllowed).toBe('move')
  })
})
