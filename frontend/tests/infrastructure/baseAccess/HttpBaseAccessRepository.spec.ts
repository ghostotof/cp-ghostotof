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

  it('grant() envoie une requête POST authentifiée par cookie, sans header CSRF (endpoint exclu)', async () => {
    const fetchMock = stubFetch({ ok: true, status: 200 } as unknown as Response)

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
