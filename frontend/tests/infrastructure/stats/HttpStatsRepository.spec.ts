import { afterEach, describe, expect, it, vi } from 'vitest'
import { HttpStatsRepository } from '../../../src/infrastructure/stats/HttpStatsRepository'
import { StatsUnavailableError } from '../../../src/domain/stats/errors/StatsUnavailableError'

function stubFetch(body: unknown, ok = true): void {
  vi.stubGlobal(
    'fetch',
    vi.fn(async () => ({ ok, json: async () => body }) as unknown as Response),
  )
}

describe('HttpStatsRepository', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('list() appelle GET /api/stats/{locale} sans credentials (endpoint public)', async () => {
    const fetchMock = vi.fn(async () => ({ ok: true, json: async () => [] }) as unknown as Response)
    vi.stubGlobal('fetch', fetchMock)

    await new HttpStatsRepository('https://api.example.test').list('fr')

    expect(fetchMock).toHaveBeenCalledWith('https://api.example.test/api/stats/fr', { method: 'GET' })
  })

  it('list() renvoie le tableau de statistiques tel que fourni par le backend', async () => {
    const body = [{ value: '+50K', label: 'Lignes de code', iconKey: 'code' }]
    stubFetch(body)

    const result = await new HttpStatsRepository('https://api.example.test').list('fr')

    expect(result).toEqual(body)
  })

  it('list() lève StatsUnavailableError sur une réponse en erreur', async () => {
    stubFetch([], false)

    await expect(new HttpStatsRepository('https://api.example.test').list('fr')).rejects.toThrow(StatsUnavailableError)
  })
})
