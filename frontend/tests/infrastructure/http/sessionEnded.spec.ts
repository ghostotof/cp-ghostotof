import { describe, expect, it } from 'vitest'
import { endsTheSession } from '../../../src/infrastructure/http/sessionEnded'

/**
 * La règle unique du frontend (#748, constat 15) : un 401, ou un 403 à une écriture partie sans jeton
 * CSRF. Le CSRF ne contrôle que les écritures et passe avant le pare-feu : sans paire de cookies,
 * c'est lui qui répond, par un 403.
 */
describe('endsTheSession', () => {
  it.each([
    [401, false],
    [401, true],
    [403, true],
  ])('%i (envoyé sans jeton : %s) finit la session', (status, sentWithoutCsrfToken) => {
    expect(endsTheSession(status, sentWithoutCsrfToken)).toBe(true)
  })

  it.each([
    [403, false],
    [200, true],
    [404, true],
    [422, true],
    [500, true],
  ])('%i (envoyé sans jeton : %s) ne finit pas la session', (status, sentWithoutCsrfToken) => {
    expect(endsTheSession(status, sentWithoutCsrfToken)).toBe(false)
  })
})
