import { describe, expect, it } from 'vitest'
import { visibleMutationError } from '../../../../src/application/admin/shared/visibleMutationError'
import { AdminCaseStudyError } from '../../../../src/domain/admin/caseStudies/errors/AdminCaseStudyError'
import { SessionEndedError } from '../../../../src/domain/auth/errors/SessionEndedError'

/**
 * `visibleMutationError` est l'unique endroit où une mutation d'administration taît la session finie
 * (#748, R3) : l'erreur du contexte est gardée telle quelle, une session finie ne pose rien (le
 * bandeau l'annonce seul), le reste devient l'échec générique du contexte.
 */
describe('visibleMutationError', () => {
  const fallback = (): AdminCaseStudyError => new AdminCaseStudyError('unknown', 'Unknown error')

  it("rend l'erreur du contexte telle quelle", () => {
    const own = new AdminCaseStudyError('validation', 'x')

    expect(visibleMutationError(own, AdminCaseStudyError, fallback)).toBe(own)
  })

  it('rend null pour une session finie : le bandeau est la seule annonce', () => {
    expect(visibleMutationError(new SessionEndedError(), AdminCaseStudyError, fallback)).toBeNull()
  })

  it("rend l'échec générique du contexte pour toute autre erreur", () => {
    const result = visibleMutationError(new Error('x'), AdminCaseStudyError, fallback)

    expect(result).toBeInstanceOf(AdminCaseStudyError)
    expect(result?.reason).toBe('unknown')
  })

  it("rend l'échec générique pour une valeur levée qui n'est pas une erreur", () => {
    expect(visibleMutationError('texte', AdminCaseStudyError, fallback)?.reason).toBe('unknown')
  })
})
