import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { HttpAdminAnonymousCvSectionRepository } from '../../../../src/infrastructure/admin/anonymousCv/HttpAdminAnonymousCvSectionRepository'
import { AdminAnonymousCvSectionError } from '../../../../src/domain/admin/anonymousCv/errors/AdminAnonymousCvSectionError'

const API_BASE_URL = 'https://api.example.test'
const BASE_PATH = `${API_BASE_URL}/api/backoffice/anonymous-cv`

const API_SECTION = {
  id: 1, locale: 'fr', title: 'Backend', skills: 'Symfony', yearsOfExperience: 12, achievements: 'Réalisations.', position: 0,
}
const INPUT = {
  locale: 'fr', title: 'Backend', skills: 'Symfony', yearsOfExperience: 12, achievements: 'Réalisations.', position: 0,
}

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

describe('HttpAdminAnonymousCvSectionRepository', () => {
  beforeEach(() => {
    document.cookie = 'XSRF-TOKEN=csrf-token-value; path=/'
  })

  afterEach(() => {
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'
    vi.unstubAllGlobals()
  })

  it('list() appelle GET avec credentials include et sans header CSRF', async () => {
    const fetchMock = vi.fn(async () => jsonResponse(200, [API_SECTION]))
    vi.stubGlobal('fetch', fetchMock)

    const sections = await new HttpAdminAnonymousCvSectionRepository(API_BASE_URL).list()

    expect(fetchMock).toHaveBeenCalledWith(BASE_PATH, { method: 'GET', credentials: 'include' })
    expect(sections).toEqual([API_SECTION])
  })

  it('create() envoie POST avec le corps JSON et le header CSRF', async () => {
    const fetchMock = vi.fn(async () => jsonResponse(201, API_SECTION))
    vi.stubGlobal('fetch', fetchMock)

    await new HttpAdminAnonymousCvSectionRepository(API_BASE_URL).create(INPUT)

    expect(fetchMock).toHaveBeenCalledWith(
      BASE_PATH,
      expect.objectContaining({
        method: 'POST',
        credentials: 'include',
        body: JSON.stringify(INPUT),
        headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'csrf-token-value' }),
      }),
    )
  })

  it("update() cible l'id dans le chemin", async () => {
    const fetchMock = vi.fn(async () => jsonResponse(200, API_SECTION))
    vi.stubGlobal('fetch', fetchMock)

    await new HttpAdminAnonymousCvSectionRepository(API_BASE_URL).update(7, INPUT)

    expect(fetchMock).toHaveBeenCalledWith(`${BASE_PATH}/7`, expect.objectContaining({ method: 'PUT' }))
  })

  it("remove() envoie DELETE sur l'id", async () => {
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }))
    vi.stubGlobal('fetch', fetchMock)

    await new HttpAdminAnonymousCvSectionRepository(API_BASE_URL).remove(7)

    expect(fetchMock).toHaveBeenCalledWith(`${BASE_PATH}/7`, expect.objectContaining({ method: 'DELETE' }))
  })

  it('traduit un 404 en erreur de domaine « not-found »', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(404, { detail: 'Not Found' })))

    const error = await new HttpAdminAnonymousCvSectionRepository(API_BASE_URL).update(99, INPUT).catch((c: unknown) => c)

    expect(error).toBeInstanceOf(AdminAnonymousCvSectionError)
    expect((error as AdminAnonymousCvSectionError).reason).toBe('not-found')
  })

  it('traduit un 422 en erreur de domaine « validation » — réalisations vides, par exemple', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => jsonResponse(422, { violations: [{ propertyPath: 'achievements', message: 'Cette valeur ne doit pas être vide.' }] })),
    )

    const error = await new HttpAdminAnonymousCvSectionRepository(API_BASE_URL)
      .create({ ...INPUT, achievements: '' })
      .catch((c: unknown) => c)

    expect(error).toBeInstanceOf(AdminAnonymousCvSectionError)
    expect((error as AdminAnonymousCvSectionError).reason).toBe('validation')
    expect((error as AdminAnonymousCvSectionError).message).toBe('Cette valeur ne doit pas être vide.')
  })
})
