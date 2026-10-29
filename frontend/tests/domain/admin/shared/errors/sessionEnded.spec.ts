import { describe, expect, it } from 'vitest'
import {
  isSessionEndedError,
  SESSION_ENDED,
} from '../../../../../src/domain/admin/shared/errors/sessionEnded'
import { AdminCaseStudyError } from '../../../../../src/domain/admin/caseStudies/errors/AdminCaseStudyError'
import { AdminOrderError } from '../../../../../src/domain/admin/shared/errors/AdminOrderError'

describe('isSessionEndedError', () => {
  it('la raison commune est « session-ended »', () => {
    expect(SESSION_ENDED).toBe('session-ended')
  })

  it("est vrai pour l'erreur d'un contexte porteuse de la raison, quelle que soit sa classe", () => {
    expect(isSessionEndedError(new AdminCaseStudyError('session-ended', 'x'))).toBe(true)
    expect(isSessionEndedError(new AdminOrderError('session-ended', 'x'))).toBe(true)
  })

  it.each([
    ['une autre raison du même contexte', new AdminCaseStudyError('unknown', 'x')],
    ['une erreur ordinaire', new Error('x')],
    ['null', null],
    ['une chaîne qui porte le mot', 'session-ended'],
  ])('est faux pour %s', (_label, value) => {
    expect(isSessionEndedError(value)).toBe(false)
  })
})
