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

  it('grant() envoie une requête POST authentifiée par cookie, sans header CSRF (endpoint exclu)', async () => {
    const fetchMock = stubFetch({ ok: true, status: 200, json: async () => ({}) } as unknown as Response)

    await new HttpBaseAccessRepository('https://api.example.test').grant()

    expect(fetchMock).toHaveBeenCalledWith('https://api.example.test/api/account/base-access', {
      method: 'POST',
      credentials: 'include',
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
})
