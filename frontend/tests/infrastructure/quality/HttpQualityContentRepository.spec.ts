import { afterEach, describe, expect, it, vi } from 'vitest'
import { HttpQualityContentRepository } from '../../../src/infrastructure/quality/HttpQualityContentRepository'
import { QualityContentUnavailableError } from '../../../src/domain/quality/errors/QualityContentUnavailableError'

function stubFetch(body: unknown, ok = true): void {
  vi.stubGlobal(
    'fetch',
    vi.fn(async () => ({ ok, json: async () => body }) as unknown as Response),
  )
}

describe('HttpQualityContentRepository', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('get() appelle GET /api/quality/{locale} sans credentials (endpoint public)', async () => {
    const fetchMock = vi.fn(async () => ({ ok: true, json: async () => ({ principles: [], traits: [] }) }) as unknown as Response)
    vi.stubGlobal('fetch', fetchMock)

    await new HttpQualityContentRepository('https://api.example.test').get('fr')

    expect(fetchMock).toHaveBeenCalledWith('https://api.example.test/api/quality/fr', { method: 'GET' })
  })

  it('get() renvoie les principes et traits tels que fournis par le backend', async () => {
    const body = {
      principles: [{ title: 'DDD', description: 'Description', iconKey: 'boxes' }],
      traits: [{ label: 'Architecture propre' }],
    }
    stubFetch(body)

    const result = await new HttpQualityContentRepository('https://api.example.test').get('fr')

    expect(result).toEqual(body)
  })

  it('get() lève QualityContentUnavailableError sur une réponse en erreur', async () => {
    stubFetch({}, false)

    await expect(new HttpQualityContentRepository('https://api.example.test').get('fr')).rejects.toThrow(
      QualityContentUnavailableError,
    )
  })
})
