import { afterEach, describe, expect, it, vi } from 'vitest'
import { HttpWatchRepository } from '../../../src/infrastructure/watch/HttpWatchRepository'
import { WatchUnavailableError } from '../../../src/domain/watch/errors/WatchUnavailableError'

const API_BASE_URL = 'https://api.example.test'

describe('HttpWatchRepository', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('get() appelle GET /api/watch sans cookie (endpoint public, #748 R23)', async () => {
    const fetchMock = vi.fn(async () => ({ ok: true, json: async () => ({}) }) as unknown as Response)
    vi.stubGlobal('fetch', fetchMock)

    await new HttpWatchRepository(API_BASE_URL).get()

    expect(fetchMock).toHaveBeenCalledWith(`${API_BASE_URL}/api/watch`, { method: 'GET', credentials: 'omit' })
  })

  it("get() lève WatchUnavailableError sur une réponse non-OK", async () => {
    vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, json: async () => ({}) }) as unknown as Response))

    await expect(new HttpWatchRepository(API_BASE_URL).get()).rejects.toBeInstanceOf(WatchUnavailableError)
  })
})
