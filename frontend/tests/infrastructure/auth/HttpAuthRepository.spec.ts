import { afterEach, describe, expect, it, vi } from 'vitest'
import { HttpAuthRepository, ME_TIMEOUT_MS } from '../../../src/infrastructure/auth/HttpAuthRepository'
import { InvalidCredentialsError } from '../../../src/domain/auth/errors/InvalidCredentialsError'
import { LoginRateLimitedError } from '../../../src/domain/auth/errors/LoginRateLimitedError'
import { fetchSettledOnlyByAbort } from '../../support/abortableFetch'

function stubFetch(status: number, body: unknown = undefined, headers: Record<string, string> = {}): ReturnType<typeof vi.fn> {
  const fetchMock = vi.fn(async () => ({
    ok: status >= 200 && status < 300,
    status,
    headers: new Headers(headers),
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
 * Un `/api/me` qui ne répond pas (#748, R49, CR5-7) : la route `login`, le garde des routes protégées et les deux
 * relectures l'attendent ; sans borne, ils attendaient sans fin. `me()` abandonne donc après `ME_TIMEOUT_MS`.
 */
describe('HttpAuthRepository.me() — la borne de temps', () => {
  afterEach(() => {
    // Les faux minuteurs ne survivent jamais à leur test : le suivant (axe-core compris) tourne sous les vrais.
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  it('envoie les cookies et un signal d\'abandon', async () => {
    const fetchMock = stubFetch(401)

    await new HttpAuthRepository('https://api.example.test').me()

    expect(fetchMock).toHaveBeenCalledWith('https://api.example.test/api/me', {
      method: 'GET',
      credentials: 'include',
      signal: expect.any(AbortSignal),
    })
  })

  it('me() abandonne après ME_TIMEOUT_MS : rejet AbortError', async () => {
    vi.useFakeTimers()
    vi.stubGlobal('fetch', fetchSettledOnlyByAbort())
    const pending = new HttpAuthRepository('https://api.example.test').me()
    const outcome = expect(pending).rejects.toMatchObject({ name: 'AbortError' })

    // Un instant avant le délai : toujours en attente, rien n'est abandonné.
    await vi.advanceTimersByTimeAsync(ME_TIMEOUT_MS - 1)
    await vi.advanceTimersByTimeAsync(1)

    await outcome
  })

  it('une réponse avant le délai lève la minuterie', async () => {
    vi.useFakeTimers()
    stubFetch(401)

    await new HttpAuthRepository('https://api.example.test').me()

    expect(vi.getTimerCount()).toBe(0)
  })

  it('un échec avant le délai lève aussi la minuterie', async () => {
    vi.useFakeTimers()
    stubFetch(503)

    await expect(new HttpAuthRepository('https://api.example.test').me()).rejects.toThrow(/503/)

    expect(vi.getTimerCount()).toBe(0)
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

  /**
   * Issue #399 : le refus de `login_throttling` répond 429 `/errors/rate-limited`,
   * comme la zone nginx `login` — les deux mènent au même message.
   */
  it('429 : LoginRateLimitedError, pas une erreur générique', async () => {
    stubFetch(429, { type: '/errors/rate-limited', status: 429 })

    await expect(new HttpAuthRepository('https://api.example.test').login(username, 'wrong')).rejects.toBeInstanceOf(LoginRateLimitedError)
  })

  it('429 de login_throttling : le délai de Retry-After accompagne l\'erreur', async () => {
    stubFetch(429, { type: '/errors/rate-limited', status: 429 }, { 'Retry-After': '900' })

    await expect(new HttpAuthRepository('https://api.example.test').login(username, 'wrong')).rejects.toMatchObject({ retryAfterSeconds: 900 })
  })

  it('429 de la zone nginx, sans Retry-After : pas de délai', async () => {
    stubFetch(429, { type: '/errors/rate-limited', status: 429 })

    await expect(new HttpAuthRepository('https://api.example.test').login(username, 'wrong')).rejects.toMatchObject({ retryAfterSeconds: null })
  })
})

/**
 * `hasWriteToken()` (#748, R8) : la session finie ne se rétablit que si le jeton d'écriture est là. Un compte
 * retrouvé par `/api/me` (le `BEARER`, httpOnly, vaut encore) sans `XSRF-TOKEN` prendrait un 403 à la première
 * écriture, lu « session finie » : l'annonce « rétablie » mentirait, et la boucle recommencerait.
 */
describe('HttpAuthRepository.hasWriteToken()', () => {
  const clearCsrfCookie = (): void => {
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'
  }

  afterEach(clearCsrfCookie)

  it('vrai quand un cookie XSRF-TOKEN non vide est lisible', () => {
    document.cookie = 'XSRF-TOKEN=jeton-de-test; path=/'

    expect(new HttpAuthRepository('https://api.example.test').hasWriteToken()).toBe(true)
  })

  it('faux sans cookie XSRF-TOKEN', () => {
    clearCsrfCookie()

    expect(new HttpAuthRepository('https://api.example.test').hasWriteToken()).toBe(false)
  })

  it('faux avec un cookie XSRF-TOKEN vide : le client n\'enverrait aucun en-tête', () => {
    document.cookie = 'XSRF-TOKEN=; path=/'

    expect(new HttpAuthRepository('https://api.example.test').hasWriteToken()).toBe(false)
  })
})

/**
 * `logout()` est une écriture : elle part avec ses cookies et le jeton CSRF lu dans `XSRF-TOKEN`, par l'envoi partagé.
 */
describe('HttpAuthRepository.logout()', () => {
  const clearCsrfCookie = (): void => {
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'
  }

  afterEach(() => {
    vi.unstubAllGlobals()
    clearCsrfCookie()
  })

  it('POST /api/logout avec les cookies et le jeton CSRF', async () => {
    document.cookie = 'XSRF-TOKEN=jeton-de-test; path=/'
    const fetchMock = stubFetch(204)

    await new HttpAuthRepository('https://api.example.test').logout()

    expect(fetchMock).toHaveBeenCalledWith('https://api.example.test/api/logout', {
      method: 'POST',
      credentials: 'include',
      headers: { 'X-XSRF-TOKEN': 'jeton-de-test' },
    })
  })

  it('un refus (même un 401 : le jeton est déjà mort) rejette, sans verdict de session', async () => {
    stubFetch(401)

    await expect(new HttpAuthRepository('https://api.example.test').logout()).rejects.toThrow('Logout failed with status 401')
  })
})
