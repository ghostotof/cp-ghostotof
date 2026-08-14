import { afterEach, describe, expect, it, vi } from 'vitest'
import { HttpAboutContentRepository } from '../../../src/infrastructure/about/HttpAboutContentRepository'
import { AboutContentUnavailableError } from '../../../src/domain/about/errors/AboutContentUnavailableError'

function stubFetch(body: unknown, ok = true): void {
  vi.stubGlobal(
    'fetch',
    vi.fn(async () => ({ ok, json: async () => body }) as unknown as Response),
  )
}

const API_RESPONSE = {
  site: {
    eyebrow: 'À propos de ce site',
    cards: [{ title: 'Architecture', description: 'Description', iconKey: 'layers' }],
  },
  me: {
    eyebrow: 'À propos de moi',
    technicalSubtitle: 'Techniquement',
    technicalCards: [{ title: 'Dev', description: 'Description', iconKey: null }],
    personalSubtitle: 'Humainement',
    personalCards: [{ title: 'Curieux', description: 'Description', iconKey: 'lightbulb' }],
    hobbiesSubtitle: 'En dehors du travail',
    hobbiesCards: [],
  },
}

describe('HttpAboutContentRepository', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('get() appelle GET /api/about/{locale} sans credentials (endpoint public)', async () => {
    const fetchMock = vi.fn(async () => ({ ok: true, json: async () => API_RESPONSE }) as unknown as Response)
    vi.stubGlobal('fetch', fetchMock)

    await new HttpAboutContentRepository('https://api.example.test').get('fr')

    expect(fetchMock).toHaveBeenCalledWith('https://api.example.test/api/about/fr', { method: 'GET' })
  })

  it('get() convertit la réponse JSON en AboutContent, en convertissant iconKey null en undefined', async () => {
    stubFetch(API_RESPONSE)

    const result = await new HttpAboutContentRepository('https://api.example.test').get('fr')

    expect(result.site.eyebrow).toBe('À propos de ce site')
    expect(result.site.cards).toEqual([{ title: 'Architecture', description: 'Description', iconKey: 'layers' }])
    expect(result.me.technicalCards[0]?.iconKey).toBeUndefined()
    expect(result.me.hobbiesCards).toEqual([])
  })

  it('get() lève AboutContentUnavailableError sur une réponse en erreur', async () => {
    stubFetch({}, false)

    await expect(new HttpAboutContentRepository('https://api.example.test').get('fr')).rejects.toThrow(
      AboutContentUnavailableError,
    )
  })
})
