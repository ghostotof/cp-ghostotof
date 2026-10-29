import { describe, expect, it } from 'vitest'
import { visibleErrorReason } from '../../../../src/presentation/ui/admin/visibleErrorReason'

describe('visibleErrorReason', () => {
  it("tait la raison « session-ended » : le bandeau de session finie est alors la seule annonce", () => {
    expect(visibleErrorReason('session-ended')).toBeNull()
  })

  it('laisse passer toute autre raison', () => {
    expect(visibleErrorReason('unknown')).toBe('unknown')
    expect(visibleErrorReason('validation')).toBe('validation')
  })

  it('rend null pour une absence de raison', () => {
    expect(visibleErrorReason(null)).toBeNull()
    expect(visibleErrorReason(undefined)).toBeNull()
  })
})
