import { afterEach, describe, expect, it, vi } from 'vitest'
import { HttpCaseStudyRepository } from '../../../src/infrastructure/caseStudies/HttpCaseStudyRepository'
import { CaseStudiesUnavailableError } from '../../../src/domain/caseStudies/errors/CaseStudiesUnavailableError'
import { CaseStudiesAccessNotGrantedError } from '../../../src/domain/caseStudies/errors/CaseStudiesAccessNotGrantedError'

function stubFetch(response: Partial<Response>): ReturnType<typeof vi.fn> {
  const fetchMock = vi.fn(async () => response as Response)
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

describe('HttpCaseStudyRepository', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('list() envoie une requête GET authentifiée par cookie (credentials: include)', async () => {
    const fetchMock = stubFetch({ ok: true, status: 200, json: async () => [] } as unknown as Response)

    await new HttpCaseStudyRepository('https://api.example.test').list('fr')

    expect(fetchMock).toHaveBeenCalledWith('https://api.example.test/api/case-studies/fr', {
      method: 'GET',
      credentials: 'include',
    })
  })

  it('list() retourne les études de cas décodées', async () => {
    const caseStudy = {
      title: 'Titre',
      problem: 'Problème.',
      solution: 'Solution.',
      tradeoffs: 'Compromis.',
      measuredResult: 'Résultat.',
    }
    stubFetch({ ok: true, status: 200, json: async () => [caseStudy] } as unknown as Response)

    const result = await new HttpCaseStudyRepository('https://api.example.test').list('fr')

    expect(result).toEqual([caseStudy])
  })

  it('list() lève CaseStudiesAccessNotGrantedError sur un 401 (aucun jeton : visiteur anonyme)', async () => {
    stubFetch({ ok: false, status: 401 } as unknown as Response)

    await expect(new HttpCaseStudyRepository('https://api.example.test').list('fr')).rejects.toThrow(
      CaseStudiesAccessNotGrantedError,
    )
  })

  it('list() lève CaseStudiesAccessNotGrantedError sur un 403 aussi (défensif : ne devrait pas se produire en pratique, cf. commentaire du repository)', async () => {
    stubFetch({ ok: false, status: 403 } as unknown as Response)

    await expect(new HttpCaseStudyRepository('https://api.example.test').list('fr')).rejects.toThrow(
      CaseStudiesAccessNotGrantedError,
    )
  })

  it('list() lève CaseStudiesUnavailableError sur un autre échec (5xx...)', async () => {
    stubFetch({ ok: false, status: 500 } as unknown as Response)

    await expect(new HttpCaseStudyRepository('https://api.example.test').list('fr')).rejects.toThrow(
      CaseStudiesUnavailableError,
    )
  })
})
