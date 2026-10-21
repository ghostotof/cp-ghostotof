import { vi } from 'vitest'

/**
 * jsdom n'implémente pas `navigator.clipboard` : un test qui copie pose le sien,
 * et le retire ensuite (`removeClipboard`), pour que l'absence — contexte non
 * sécurisé, navigateur ancien — reste un cas testable à part.
 */
export type WriteText = (text: string) => Promise<void>

export function stubClipboard(writeText: WriteText = vi.fn<WriteText>(async () => undefined)): WriteText {
  Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })

  return writeText
}

export function removeClipboard(): void {
  Reflect.deleteProperty(navigator, 'clipboard')
}
