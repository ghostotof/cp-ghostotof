import { afterEach, describe, expect, it, vi } from 'vitest'
import { HttpContributionRepository } from '../../../src/infrastructure/contributions/HttpContributionRepository'
import { ContributionsUnavailableError } from '../../../src/domain/contributions/errors/ContributionsUnavailableError'

const API_BASE_URL = 'https://api.example.test'

const PAYLOAD = [
  {
    title: 'Retry de transport, re-prompt de validation',
    project: 'symfony/ai',
    reference: 'Issue #1688',
    url: 'https://github.com/symfony/ai/issues/1688',
    summary: 'Deux opérations sous un seul mot.',
    body: 'Premier paragraphe.\n\nSecond paragraphe.',
  },
]

function stubFetch(body: unknown, ok = true): void {
  vi.stubGlobal(
    'fetch',
    vi.fn(async () => ({ ok, json: async () => body }) as unknown as Response),
  )
}

describe('HttpContributionRepository', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('list() appelle GET /api/contributions/{locale} sans credentials (endpoint public)', async () => {
    const fetchMock = vi.fn(async () => ({ ok: true, json: async () => [] }) as unknown as Response)
    vi.stubGlobal('fetch', fetchMock)

    await new HttpContributionRepository(API_BASE_URL).list('fr')

    expect(fetchMock).toHaveBeenCalledWith(`${API_BASE_URL}/api/contributions/fr`, { method: 'GET' })
  })

  it('list() suit la locale demandée', async () => {
    const fetchMock = vi.fn(async () => ({ ok: true, json: async () => [] }) as unknown as Response)
    vi.stubGlobal('fetch', fetchMock)

    await new HttpContributionRepository(API_BASE_URL).list('en')

    expect(fetchMock).toHaveBeenCalledWith(`${API_BASE_URL}/api/contributions/en`, { method: 'GET' })
  })

  it('list() renvoie les contributions telles que servies par l\'API', async () => {
    stubFetch(PAYLOAD)

    const result = await new HttpContributionRepository(API_BASE_URL).list('fr')

    expect(result).toEqual(PAYLOAD)
  })

  it('list() lève une erreur de domaine quand la réponse est en échec', async () => {
    stubFetch([], false)

    await expect(new HttpContributionRepository(API_BASE_URL).list('fr')).rejects.toBeInstanceOf(
      ContributionsUnavailableError,
    )
  })
})
