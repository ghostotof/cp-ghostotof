import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { HttpAdminContributionRepository } from '../../../../src/infrastructure/admin/contributions/HttpAdminContributionRepository'
import { AdminContributionError } from '../../../../src/domain/admin/contributions/errors/AdminContributionError'

const API_BASE_URL = 'https://api.example.test'
const BASE_PATH = `${API_BASE_URL}/api/backoffice/contributions`

const API_CONTRIBUTION = {
  id: 1,
  locale: 'fr',
  title: 'Retry de transport',
  project: 'symfony/ai',
  reference: 'Issue #1688',
  url: 'https://github.com/symfony/ai/issues/1688',
  summary: 'Chapeau.',
  body: 'Corps.',
  position: 0,
}

const INPUT = {
  locale: 'fr',
  title: 'Retry de transport',
  project: 'symfony/ai',
  reference: 'Issue #1688',
  url: 'https://github.com/symfony/ai/issues/1688',
  summary: 'Chapeau.',
  body: 'Corps.',
  position: 0,
}

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

describe('HttpAdminContributionRepository', () => {
  beforeEach(() => {
    document.cookie = 'XSRF-TOKEN=csrf-token-value; path=/'
  })

  afterEach(() => {
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'
    vi.unstubAllGlobals()
  })

  it('list() appelle GET avec credentials include et sans header CSRF', async () => {
    const fetchMock = vi.fn(async () => jsonResponse(200, [API_CONTRIBUTION]))
    vi.stubGlobal('fetch', fetchMock)

    const contributions = await new HttpAdminContributionRepository(API_BASE_URL).list()

    // Match exact : garantit qu'aucun header (donc pas de X-XSRF-TOKEN) n'est
    // ajouté sur une simple lecture.
    expect(fetchMock).toHaveBeenCalledWith(BASE_PATH, { method: 'GET', credentials: 'include' })
    expect(contributions).toEqual([API_CONTRIBUTION])
  })

  it('create() envoie POST avec le header CSRF et le corps JSON', async () => {
    const fetchMock = vi.fn(async () => jsonResponse(201, API_CONTRIBUTION))
    vi.stubGlobal('fetch', fetchMock)

    await new HttpAdminContributionRepository(API_BASE_URL).create(INPUT)

    expect(fetchMock).toHaveBeenCalledWith(
      BASE_PATH,
      expect.objectContaining({
        method: 'POST',
        credentials: 'include',
        body: JSON.stringify(INPUT),
      }),
    )
  })

  it('update() cible l\'id dans le chemin', async () => {
    const fetchMock = vi.fn(async () => jsonResponse(200, API_CONTRIBUTION))
    vi.stubGlobal('fetch', fetchMock)

    await new HttpAdminContributionRepository(API_BASE_URL).update(7, INPUT)

    expect(fetchMock).toHaveBeenCalledWith(`${BASE_PATH}/7`, expect.objectContaining({ method: 'PUT' }))
  })

  it('remove() envoie DELETE sur l\'id', async () => {
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }))
    vi.stubGlobal('fetch', fetchMock)

    await new HttpAdminContributionRepository(API_BASE_URL).remove(7)

    expect(fetchMock).toHaveBeenCalledWith(`${BASE_PATH}/7`, expect.objectContaining({ method: 'DELETE' }))
  })

  it('traduit un 404 en erreur de domaine « not-found »', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(404, { detail: 'Not Found' })))

    const error = await new HttpAdminContributionRepository(API_BASE_URL)
      .update(99, INPUT)
      .catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(AdminContributionError)
    expect((error as AdminContributionError).reason).toBe('not-found')
  })

  it('traduit un 422 en erreur de domaine « validation »', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => jsonResponse(422, { violations: [{ propertyPath: 'url', message: 'Cette URL est invalide.' }] })),
    )

    const error = await new HttpAdminContributionRepository(API_BASE_URL)
      .create({ ...INPUT, url: 'pas-une-url' })
      .catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(AdminContributionError)
    expect((error as AdminContributionError).reason).toBe('validation')
  })
})
