import { afterEach, describe, expect, it, vi } from 'vitest'
import { HttpExperienceTechnologyRepository } from '../../../src/infrastructure/experience/HttpExperienceTechnologyRepository'
import { ExperienceTechnologiesUnavailableError } from '../../../src/domain/experience/errors/ExperienceTechnologiesUnavailableError'

function stubFetch(body: unknown, ok = true): void {
  vi.stubGlobal(
    'fetch',
    vi.fn(async () => ({ ok, json: async () => body }) as unknown as Response),
  )
}

describe('HttpExperienceTechnologyRepository', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('list() appelle GET /api/experience/technologies sans credentials (endpoint public)', async () => {
    const fetchMock = vi.fn(async () => ({ ok: true, json: async () => [] }) as unknown as Response)
    vi.stubGlobal('fetch', fetchMock)

    await new HttpExperienceTechnologyRepository('https://api.example.test').list('fr')

    expect(fetchMock).toHaveBeenCalledWith('https://api.example.test/api/experience/technologies', { method: 'GET' })
  })

  it('list() convertit la réponse JSON en entités et calcule la durée localisée', async () => {
    stubFetch([{ name: 'PHP', years: 13.5, iconKey: 'php', relatedTechnology: { name: 'HTML / CSS / JavaScript' } }])

    const result = await new HttpExperienceTechnologyRepository('https://api.example.test').list('fr')

    expect(result).toEqual([
      { name: 'PHP', years: 13.5, duration: '~13,5 ans', iconKey: 'php', relatedTechnology: { name: 'HTML / CSS / JavaScript' } },
    ])
  })

  it('list() convertit les champs iconKey/relatedTechnology null en undefined', async () => {
    stubFetch([{ name: 'MySQL', years: 13.5, iconKey: null, relatedTechnology: null }])

    const result = await new HttpExperienceTechnologyRepository('https://api.example.test').list('fr')

    expect(result[0].iconKey).toBeUndefined()
    expect(result[0].relatedTechnology).toBeUndefined()
  })

  it('list() trie les technologies par années décroissantes', async () => {
    stubFetch([
      { name: 'Docker', years: 6.5, iconKey: null, relatedTechnology: null },
      { name: 'PHP', years: 13.5, iconKey: null, relatedTechnology: null },
      { name: 'Symfony', years: 9.5, iconKey: null, relatedTechnology: null },
    ])

    const result = await new HttpExperienceTechnologyRepository('https://api.example.test').list('fr')

    expect(result.map((technology) => technology.name)).toEqual(['PHP', 'Symfony', 'Docker'])
  })

  it('list() calcule la durée en anglais pour la locale en', async () => {
    stubFetch([{ name: 'PHP', years: 13.5, iconKey: null, relatedTechnology: null }])

    const result = await new HttpExperienceTechnologyRepository('https://api.example.test').list('en')

    expect(result[0].duration).toBe('~13.5 years')
  })

  it('list() lève ExperienceTechnologiesUnavailableError sur une réponse en erreur', async () => {
    stubFetch([], false)

    await expect(new HttpExperienceTechnologyRepository('https://api.example.test').list('fr')).rejects.toThrow(
      ExperienceTechnologiesUnavailableError,
    )
  })
})
