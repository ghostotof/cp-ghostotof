import { describe, expect, it } from 'vitest'
import { retryAfterMinutes } from '../../../src/presentation/ui/retryAfterMinutes'

describe('retryAfterMinutes', () => {
  it.each([
    [0, 1],
    [30, 1],
    [60, 1],
    [61, 2],
    [900, 15],
  ])('%i s → %i min : arrondi au supérieur, jamais moins d\'une minute', (seconds, minutes) => {
    expect(retryAfterMinutes(seconds)).toBe(minutes)
  })
})
