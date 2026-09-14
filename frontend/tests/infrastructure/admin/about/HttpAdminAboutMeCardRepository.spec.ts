import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { HttpAdminAboutMeCardRepository } from '../../../../src/infrastructure/admin/about/HttpAdminAboutMeCardRepository'
import { AdminAboutError } from '../../../../src/domain/admin/about/errors/AdminAboutError'

const API_BASE_URL = 'https://api.example.test'
const CARD_ID = '019968a0-0000-7000-8000-000000000001'
const UPDATED_CARD_ID = '019968a0-0000-7000-8000-000000000002'
const MISSING_CARD_ID = '019968a0-0000-7000-8000-000000000999'

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

describe('HttpAdminAboutMeCardRepository', () => {
  beforeEach(() => {
    document.cookie = 'XSRF-TOKEN=csrf-token-value; path=/'
  })

  afterEach(() => {
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'
    vi.unstubAllGlobals()
  })

  it('list(locale) appelle GET filtré par locale uniquement (sans category)', async () => {
    const fetchMock = vi.fn(async () =>
      jsonResponse(200, [{ id: CARD_ID, locale: 'fr', category: 'technical', title: 'Dev senior', description: 'D', iconKey: 'code', position: 0 }]),
    )
    vi.stubGlobal('fetch', fetchMock)

    const repository = new HttpAdminAboutMeCardRepository(API_BASE_URL)
    const cards = await repository.list('fr')

    expect(fetchMock).toHaveBeenCalledWith(`${API_BASE_URL}/api/backoffice/about/me-cards?locale=fr`, {
      method: 'GET',
      credentials: 'include',
    })
    expect(cards).toEqual([{ id: CARD_ID, locale: 'fr', category: 'technical', title: 'Dev senior', description: 'D', iconKey: 'code', position: 0 }])
  })

  it('list(locale, category) ajoute le filtre category en query string', async () => {
    const fetchMock = vi.fn(async () => jsonResponse(200, []))
    vi.stubGlobal('fetch', fetchMock)

    const repository = new HttpAdminAboutMeCardRepository(API_BASE_URL)
    await repository.list('fr', 'hobby')

    expect(fetchMock).toHaveBeenCalledWith(`${API_BASE_URL}/api/backoffice/about/me-cards?locale=fr&category=hobby`, {
      method: 'GET',
      credentials: 'include',
    })
  })

  it('create() envoie POST avec le header CSRF et le corps JSON', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => jsonResponse(201, { id: UPDATED_CARD_ID, locale: 'fr', category: 'hobby', title: 'Musique', description: 'D', iconKey: null, position: 0 })),
    )

    const repository = new HttpAdminAboutMeCardRepository(API_BASE_URL)
    await repository.create({ locale: 'fr', category: 'hobby', title: 'Musique', description: 'D', iconKey: null, position: 0 })

    expect(vi.mocked(fetch)).toHaveBeenCalledWith(
      `${API_BASE_URL}/api/backoffice/about/me-cards`,
      expect.objectContaining({ method: 'POST', headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'csrf-token-value' }) }),
    )
  })

  it('update() envoie PUT vers /{id} avec le header CSRF', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => jsonResponse(200, { id: UPDATED_CARD_ID, locale: 'fr', category: 'hobby', title: 'Musique', description: 'D', iconKey: null, position: 0 })),
    )

    const repository = new HttpAdminAboutMeCardRepository(API_BASE_URL)
    await repository.update(UPDATED_CARD_ID, { locale: 'fr', category: 'hobby', title: 'Musique', description: 'D', iconKey: null, position: 0 })

    expect(vi.mocked(fetch)).toHaveBeenCalledWith(
      `${API_BASE_URL}/api/backoffice/about/me-cards/${UPDATED_CARD_ID}`,
      expect.objectContaining({ method: 'PUT', headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'csrf-token-value' }) }),
    )
  })

  it('remove() envoie DELETE vers /{id} avec le header CSRF', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(null, { status: 204 })))

    const repository = new HttpAdminAboutMeCardRepository(API_BASE_URL)
    await repository.remove(UPDATED_CARD_ID)

    expect(vi.mocked(fetch)).toHaveBeenCalledWith(
      `${API_BASE_URL}/api/backoffice/about/me-cards/${UPDATED_CARD_ID}`,
      expect.objectContaining({ method: 'DELETE', headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'csrf-token-value' }) }),
    )
  })

  it('lève une erreur "not-found" sur 404', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(404, { detail: 'Not Found' })))

    const repository = new HttpAdminAboutMeCardRepository(API_BASE_URL)
    const error = await repository
      .update(MISSING_CARD_ID, { locale: 'fr', category: 'technical', title: 'x', description: 'x', iconKey: null, position: 0 })
      .catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(AdminAboutError)
    expect((error as AdminAboutError).reason).toBe('not-found')
  })

  it('lève une erreur "validation" sur 422', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(422, { violations: [{ propertyPath: 'title', message: 'This value should not be blank.' }] })))

    const repository = new HttpAdminAboutMeCardRepository(API_BASE_URL)
    const error = await repository
      .create({ locale: 'fr', category: 'technical', title: '', description: 'x', iconKey: null, position: 0 })
      .catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(AdminAboutError)
    expect((error as AdminAboutError).reason).toBe('validation')
  })

  it('lève une erreur "unknown" sur un statut inattendu', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(null, { status: 500 })))

    const repository = new HttpAdminAboutMeCardRepository(API_BASE_URL)
    const error = await repository.list('fr').catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(AdminAboutError)
    expect((error as AdminAboutError).reason).toBe('unknown')
  })
})
