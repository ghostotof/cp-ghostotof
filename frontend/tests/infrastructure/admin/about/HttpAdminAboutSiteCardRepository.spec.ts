import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { HttpAdminAboutSiteCardRepository } from '../../../../src/infrastructure/admin/about/HttpAdminAboutSiteCardRepository'
import { AdminAboutError } from '../../../../src/domain/admin/about/errors/AdminAboutError'

const API_BASE_URL = 'https://api.example.test'

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

describe('HttpAdminAboutSiteCardRepository', () => {
  beforeEach(() => {
    document.cookie = 'XSRF-TOKEN=csrf-token-value; path=/'
  })

  afterEach(() => {
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'
    vi.unstubAllGlobals()
  })

  it('list() appelle GET filtré par locale, sans header CSRF, et mappe la réponse (iconKey absent => null)', async () => {
    const fetchMock = vi.fn(async () => jsonResponse(200, [{ id: 1, locale: 'fr', title: 'Architecture', description: 'D', position: 0 }]))
    vi.stubGlobal('fetch', fetchMock)

    const repository = new HttpAdminAboutSiteCardRepository(API_BASE_URL)
    const cards = await repository.list('fr')

    expect(fetchMock).toHaveBeenCalledWith(`${API_BASE_URL}/api/backoffice/about/site-cards?locale=fr`, {
      method: 'GET',
      credentials: 'include',
    })
    expect(cards).toEqual([{ id: 1, locale: 'fr', title: 'Architecture', description: 'D', iconKey: null, position: 0 }])
  })

  it('create() envoie POST avec le header CSRF et le corps JSON', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(201, { id: 2, locale: 'fr', title: 'Stack', description: 'D', iconKey: 'server', position: 1 })))

    const repository = new HttpAdminAboutSiteCardRepository(API_BASE_URL)
    await repository.create({ locale: 'fr', title: 'Stack', description: 'D', iconKey: 'server', position: 1 })

    expect(vi.mocked(fetch)).toHaveBeenCalledWith(
      `${API_BASE_URL}/api/backoffice/about/site-cards`,
      expect.objectContaining({ method: 'POST', headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'csrf-token-value' }) }),
    )
  })

  it('update() envoie PUT vers /{id} avec le header CSRF', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(200, { id: 2, locale: 'fr', title: 'Stack', description: 'D', iconKey: 'server', position: 1 })))

    const repository = new HttpAdminAboutSiteCardRepository(API_BASE_URL)
    await repository.update(2, { locale: 'fr', title: 'Stack', description: 'D', iconKey: 'server', position: 1 })

    expect(vi.mocked(fetch)).toHaveBeenCalledWith(
      `${API_BASE_URL}/api/backoffice/about/site-cards/2`,
      expect.objectContaining({ method: 'PUT', headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'csrf-token-value' }) }),
    )
  })

  it('remove() envoie DELETE vers /{id} avec le header CSRF', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(null, { status: 204 })))

    const repository = new HttpAdminAboutSiteCardRepository(API_BASE_URL)
    await repository.remove(2)

    expect(vi.mocked(fetch)).toHaveBeenCalledWith(
      `${API_BASE_URL}/api/backoffice/about/site-cards/2`,
      expect.objectContaining({ method: 'DELETE', headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'csrf-token-value' }) }),
    )
  })

  it('lève une erreur "not-found" sur 404', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(404, { detail: 'Not Found' })))

    const repository = new HttpAdminAboutSiteCardRepository(API_BASE_URL)
    const error = await repository
      .update(999, { locale: 'fr', title: 'x', description: 'x', iconKey: null, position: 0 })
      .catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(AdminAboutError)
    expect((error as AdminAboutError).reason).toBe('not-found')
  })

  it('lève une erreur "validation" sur 422', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(422, { violations: [{ propertyPath: 'title', message: 'This value should not be blank.' }] })))

    const repository = new HttpAdminAboutSiteCardRepository(API_BASE_URL)
    const error = await repository.create({ locale: 'fr', title: '', description: 'x', iconKey: null, position: 0 }).catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(AdminAboutError)
    expect((error as AdminAboutError).reason).toBe('validation')
  })

  it('lève une erreur "unknown" sur un statut inattendu', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(null, { status: 500 })))

    const repository = new HttpAdminAboutSiteCardRepository(API_BASE_URL)
    const error = await repository.list('fr').catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(AdminAboutError)
    expect((error as AdminAboutError).reason).toBe('unknown')
  })
})
