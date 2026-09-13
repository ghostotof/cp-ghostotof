import { afterEach, describe, expect, it, vi } from 'vitest'
import { HttpAuthRepository } from '../../../src/infrastructure/auth/HttpAuthRepository'
import { InvalidCredentialsError } from '../../../src/domain/auth/errors/InvalidCredentialsError'

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

/**
 * Le backend exige X-Requested-With sur /api/login_check (LoginCsrfRequestListener,
 * issue #76) : un formulaire HTML cross-site ne peut pas poser cet en-tête,
 * un fetch() du SPA le pose sans peine. Sans lui, la connexion répond 403.
 */
describe('HttpAuthRepository.login()', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  // Identifiants de test factices, déclarés séparément : un couple
  // username/password littéral dans un même objet déclenche GitGuardian.
  const username = 'jane'
  const password = 'not-a-real-password'

  it('envoie un POST JSON avec cookies et l\'en-tête X-Requested-With', async () => {
    const fetchMock = stubFetch(200, { user: { username, roles: ['ROLE_TRUSTED', 'ROLE_USER'] } })

    await new HttpAuthRepository('https://api.example.test').login(username, password)

    expect(fetchMock).toHaveBeenCalledWith('https://api.example.test/api/login_check', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' },
      body: JSON.stringify({ username, password }),
    })
  })

  it('200 : renvoie l\'utilisateur du corps', async () => {
    stubFetch(200, { user: { username, roles: ['ROLE_TRUSTED', 'ROLE_USER'] } })

    await expect(new HttpAuthRepository('https://api.example.test').login(username, password)).resolves.toEqual({
      username: 'jane',
      roles: ['ROLE_TRUSTED', 'ROLE_USER'],
    })
  })

  it('401 : InvalidCredentialsError', async () => {
    stubFetch(401)

    await expect(new HttpAuthRepository('https://api.example.test').login(username, 'wrong')).rejects.toBeInstanceOf(InvalidCredentialsError)
  })
})
