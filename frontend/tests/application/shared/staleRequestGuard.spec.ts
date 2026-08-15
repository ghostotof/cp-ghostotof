import { describe, expect, it } from 'vitest'
import { createStaleRequestGuard } from '../../../src/application/shared/staleRequestGuard'

describe('createStaleRequestGuard', () => {
  it('considère le premier jeton commencé comme courant tant qu\'aucun autre n\'a démarré', () => {
    const guard = createStaleRequestGuard()

    const token = guard.begin()

    expect(guard.isCurrent(token)).toBe(true)
  })

  it("invalide un jeton dès qu'un appel plus récent a démarré", () => {
    const guard = createStaleRequestGuard()

    const first = guard.begin()
    const second = guard.begin()

    expect(guard.isCurrent(first)).toBe(false)
    expect(guard.isCurrent(second)).toBe(true)
  })

  it('reste valide même quand plusieurs appels obsolètes se sont succédé avant lui', () => {
    const guard = createStaleRequestGuard()

    guard.begin()
    guard.begin()
    const latest = guard.begin()

    expect(guard.isCurrent(latest)).toBe(true)
  })
})
