import { afterEach, describe, expect, it, vi } from 'vitest'
import { HttpBaseAccessRepository } from '../../../src/infrastructure/baseAccess/HttpBaseAccessRepository'
import { BaseAccessError } from '../../../src/domain/baseAccess/errors/BaseAccessError'

function stubFetch(response: Partial<Response>): ReturnType<typeof vi.fn> {
  const fetchMock = vi.fn(async () => response as Response)
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

describe('HttpBaseAccessRepository', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it("grant() lit l'échéance du jeton dans le corps (ISO 8601)", async () => {
    stubFetch({ ok: true, status: 200, json: async () => ({ roles: ['ROLE_USER'], expiresAt: '2026-09-13T12:00:00+00:00' }) } as unknown as Response)

    const grant = await new HttpBaseAccessRepository('https://api.example.test').grant()

    expect(grant.expiresAt?.toISOString()).toBe('2026-09-13T12:00:00.000Z')
  })

  it.each([
    ['corps absent', async () => { throw new Error('no body') }],
    ['champ absent', async () => ({ roles: ['ROLE_USER'] })],
    ['date illisible', async () => ({ expiresAt: 'bientôt' })],
  ])("grant() rend expiresAt=null plutôt que d'échouer quand l'échéance manque (%s) : l'accès est obtenu, le cookie est posé", async (_label, json) => {
    stubFetch({ ok: true, status: 200, json } as unknown as Response)

    await expect(new HttpBaseAccessRepository('https://api.example.test').grant()).resolves.toEqual({ expiresAt: null })
  })

  it('grant() envoie un POST avec cookies, sans header CSRF (endpoint exclu) mais avec X-Requested-With (anti login-CSRF, issue #76)', async () => {
    const fetchMock = stubFetch({ ok: true, status: 200, json: async () => ({}) } as unknown as Response)

    await new HttpBaseAccessRepository('https://api.example.test').grant()

    expect(fetchMock).toHaveBeenCalledWith('https://api.example.test/api/account/base-access', {
      method: 'POST',
      credentials: 'include',
      headers: { 'X-Requested-With': 'fetch' },
    })
  })

  it("grant() lève BaseAccessError('rate-limited') sur un 429", async () => {
    stubFetch({ ok: false, status: 429 } as unknown as Response)

    await expect(new HttpBaseAccessRepository('https://api.example.test').grant()).rejects.toMatchObject({
      reason: 'rate-limited',
    } satisfies Partial<BaseAccessError>)
  })

  it("grant() lève BaseAccessError('unknown') sur un autre échec", async () => {
    stubFetch({ ok: false, status: 500 } as unknown as Response)

    await expect(new HttpBaseAccessRepository('https://api.example.test').grant()).rejects.toMatchObject({
      reason: 'unknown',
    } satisfies Partial<BaseAccessError>)
  })

  describe('rejeu unique sur 401 (cookie mort effacé par ce 401, #748 R24)', () => {
    const URL = 'https://api.example.test/api/account/base-access'
    const okBody = { ok: true, status: 200, json: async () => ({ expiresAt: '2026-09-13T12:00:00+00:00' }) } as unknown as Response
    const refused = (status: number): Response => ({ ok: false, status }) as unknown as Response

    function stubSequence(...responses: Array<Response | Error>): ReturnType<typeof vi.fn> {
      const fetchMock = vi.fn()
      for (const response of responses) {
        if (response instanceof Error) {
          fetchMock.mockRejectedValueOnce(response)
        } else {
          fetchMock.mockResolvedValueOnce(response)
        }
      }
      vi.stubGlobal('fetch', fetchMock)
      return fetchMock
    }

    it("un 401 (cookie mort, effacé par ce 401) : un second essai, qui aboutit", async () => {
      const fetchMock = stubSequence(refused(401), okBody)

      const grant = await new HttpBaseAccessRepository('https://api.example.test').grant()

      expect(fetchMock).toHaveBeenCalledTimes(2)
      expect(grant.expiresAt?.toISOString()).toBe('2026-09-13T12:00:00.000Z')
      // Le second essai est identique au premier : mêmes credentials, même en-tête anti login-CSRF.
      expect(fetchMock).toHaveBeenNthCalledWith(2, URL, {
        method: 'POST',
        credentials: 'include',
        headers: { 'X-Requested-With': 'fetch' },
      })
    })

    it("deux 401 : BaseAccessError('unknown'), deux appels seulement", async () => {
      const fetchMock = stubSequence(refused(401), refused(401), okBody)

      await expect(new HttpBaseAccessRepository('https://api.example.test').grant()).rejects.toMatchObject({
        reason: 'unknown',
      } satisfies Partial<BaseAccessError>)
      expect(fetchMock).toHaveBeenCalledTimes(2)
    })

    it("un 401 puis un 429 : le second essai est jugé comme d'habitude (rate-limited)", async () => {
      const fetchMock = stubSequence(refused(401), refused(429))

      await expect(new HttpBaseAccessRepository('https://api.example.test').grant()).rejects.toMatchObject({
        reason: 'rate-limited',
      } satisfies Partial<BaseAccessError>)
      expect(fetchMock).toHaveBeenCalledTimes(2)
    })

    it.each([[429], [403], [500]])('un %i au premier essai : aucun rejeu', async (status) => {
      const fetchMock = stubSequence(refused(status), okBody)

      await expect(new HttpBaseAccessRepository('https://api.example.test').grant()).rejects.toBeInstanceOf(BaseAccessError)
      expect(fetchMock).toHaveBeenCalledTimes(1)
    })

    it('un échec réseau au premier essai : aucun rejeu, le rejet remonte', async () => {
      const failure = new TypeError('Failed to fetch')
      const fetchMock = stubSequence(failure, okBody)

      await expect(new HttpBaseAccessRepository('https://api.example.test').grant()).rejects.toBe(failure)
      expect(fetchMock).toHaveBeenCalledTimes(1)
    })
  })
})
