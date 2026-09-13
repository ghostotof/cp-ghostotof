import { afterEach, describe, expect, it, vi } from 'vitest'
import { HttpAuthRepository } from '../../../src/infrastructure/auth/HttpAuthRepository'

function stubFetch(status: number, body: unknown = undefined): ReturnType<typeof vi.fn> {
  const fetchMock = vi.fn(async () => ({
    ok: status >= 200 && status < 300,
    status,
    json: async () => body,
  }) as unknown as Response)
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

/**
 * Le JWT est httpOnly : le frontend ne peut connaître son palier (ADR 0003
 * D1) qu'à travers la réponse de /api/me, dont le code HTTP porte l'info :
 * 401 = aucun jeton valide, 403 = jeton valide mais sans ROLE_TRUSTED (le
 * jeton D6, pinné côté backend par BaseAccessControllerTest), 200 = compte
 * de confiance.
 */
describe('HttpAuthRepository.me()', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('401 : session anonyme', async () => {
    stubFetch(401)

    await expect(new HttpAuthRepository('https://api.example.test').me()).resolves.toEqual({ tier: 'anonymous', user: null })
  })

  it('403 : palier de base, sans identité (le jeton D6 ne correspond à aucun compte)', async () => {
    stubFetch(403)

    await expect(new HttpAuthRepository('https://api.example.test').me()).resolves.toEqual({ tier: 'base', user: null })
  })

  it('200 : palier de confiance, avec l\'utilisateur renvoyé', async () => {
    stubFetch(200, { user: { username: 'jane', roles: ['ROLE_TRUSTED', 'ROLE_USER'] } })

    await expect(new HttpAuthRepository('https://api.example.test').me()).resolves.toEqual({
      tier: 'trusted',
      user: { username: 'jane', roles: ['ROLE_TRUSTED', 'ROLE_USER'] },
    })
  })

  it('autre échec (5xx) : rejette, l\'appelant ne doit pas conclure « anonyme » sur une panne', async () => {
    stubFetch(503)

    await expect(new HttpAuthRepository('https://api.example.test').me()).rejects.toThrow(/503/)
  })
})
