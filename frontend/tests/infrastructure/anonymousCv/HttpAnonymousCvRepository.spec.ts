import { afterEach, describe, expect, it, vi } from 'vitest'
import { HttpAnonymousCvRepository } from '../../../src/infrastructure/anonymousCv/HttpAnonymousCvRepository'
import { AnonymousCvUnavailableError } from '../../../src/domain/anonymousCv/errors/AnonymousCvUnavailableError'
import { AnonymousCvAccessNotGrantedError } from '../../../src/domain/anonymousCv/errors/AnonymousCvAccessNotGrantedError'

function stubFetch(response: Partial<Response>): ReturnType<typeof vi.fn> {
  const fetchMock = vi.fn(async () => response as Response)
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

describe('HttpAnonymousCvRepository', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('list() envoie une requête GET authentifiée par cookie sur /api/anonymous-cv/{locale} — jamais /api/cv (ROLE_TRUSTED)', async () => {
    const fetchMock = stubFetch({ ok: true, status: 200, json: async () => [] } as unknown as Response)

    await new HttpAnonymousCvRepository('https://api.example.test').list('en')

    expect(fetchMock).toHaveBeenCalledWith('https://api.example.test/api/anonymous-cv/en', {
      method: 'GET',
      credentials: 'include',
    })
  })

  it('list() retourne les sections décodées', async () => {
    const section = { title: 'Backend', skills: 'Symfony', yearsOfExperience: 12, achievements: 'API multi-tenant.' }
    stubFetch({ ok: true, status: 200, json: async () => [section] } as unknown as Response)

    const result = await new HttpAnonymousCvRepository('https://api.example.test').list('fr')

    expect(result).toEqual([section])
  })

  it.each([401, 403])('list() lève AnonymousCvAccessNotGrantedError sur un %d', async (status) => {
    stubFetch({ ok: false, status } as unknown as Response)

    await expect(new HttpAnonymousCvRepository('https://api.example.test').list('fr')).rejects.toThrow(
      AnonymousCvAccessNotGrantedError,
    )
  })

  it('list() lève AnonymousCvUnavailableError sur un autre échec (5xx...)', async () => {
    stubFetch({ ok: false, status: 500 } as unknown as Response)

    await expect(new HttpAnonymousCvRepository('https://api.example.test').list('fr')).rejects.toThrow(
      AnonymousCvUnavailableError,
    )
  })
})
