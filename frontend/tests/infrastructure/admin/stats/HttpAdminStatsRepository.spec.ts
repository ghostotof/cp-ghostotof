import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { HttpAdminStatsRepository } from '../../../../src/infrastructure/admin/stats/HttpAdminStatsRepository'
import { AdminStatsError } from '../../../../src/domain/admin/stats/errors/AdminStatsError'

const API_BASE_URL = 'https://api.example.test'

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

describe('HttpAdminStatsRepository', () => {
  beforeEach(() => {
    document.cookie = 'XSRF-TOKEN=csrf-token-value; path=/'
  })

  afterEach(() => {
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'
    vi.unstubAllGlobals()
  })

  it('list() appelle GET filtré par locale, sans header CSRF, et mappe la réponse', async () => {
    const fetchMock = vi.fn(async () => jsonResponse(200, [{ id: 1, locale: 'fr', value: '+50K', label: 'Lignes de code', iconKey: 'code', position: 0 }]))
    vi.stubGlobal('fetch', fetchMock)

    const repository = new HttpAdminStatsRepository(API_BASE_URL)
    const stats = await repository.list('fr')

    expect(fetchMock).toHaveBeenCalledWith(`${API_BASE_URL}/api/backoffice/stats?locale=fr`, {
      method: 'GET',
      credentials: 'include',
    })
    expect(stats).toEqual([{ id: 1, locale: 'fr', value: '+50K', label: 'Lignes de code', iconKey: 'code', position: 0 }])
  })

  it('create() envoie POST avec le header CSRF et le corps JSON', async () => {
    const fetchMock = vi.fn(async () => jsonResponse(201, { id: 2, locale: 'fr', value: '10+', label: 'Technologies', iconKey: 'box', position: 1 }))
    vi.stubGlobal('fetch', fetchMock)

    const repository = new HttpAdminStatsRepository(API_BASE_URL)
    await repository.create({ locale: 'fr', value: '10+', label: 'Technologies', iconKey: 'box', position: 1 })

    expect(fetchMock).toHaveBeenCalledWith(
      `${API_BASE_URL}/api/backoffice/stats`,
      expect.objectContaining({
        method: 'POST',
        credentials: 'include',
        headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'csrf-token-value' }),
        body: JSON.stringify({ locale: 'fr', value: '10+', label: 'Technologies', iconKey: 'box', position: 1 }),
      }),
    )
  })

  it('update() envoie PUT vers /{id} avec le header CSRF', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(200, { id: 2, locale: 'fr', value: '15+', label: 'Technologies', iconKey: 'box', position: 1 })))

    const repository = new HttpAdminStatsRepository(API_BASE_URL)
    await repository.update(2, { locale: 'fr', value: '15+', label: 'Technologies', iconKey: 'box', position: 1 })

    expect(vi.mocked(fetch)).toHaveBeenCalledWith(
      `${API_BASE_URL}/api/backoffice/stats/2`,
      expect.objectContaining({ method: 'PUT', headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'csrf-token-value' }) }),
    )
  })

  it('remove() envoie DELETE vers /{id} avec le header CSRF', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(null, { status: 204 })))

    const repository = new HttpAdminStatsRepository(API_BASE_URL)
    await repository.remove(2)

    expect(vi.mocked(fetch)).toHaveBeenCalledWith(
      `${API_BASE_URL}/api/backoffice/stats/2`,
      expect.objectContaining({ method: 'DELETE', headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'csrf-token-value' }) }),
    )
  })

  it('lève une erreur "not-found" sur 404', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(404, { detail: 'Not Found' })))

    const repository = new HttpAdminStatsRepository(API_BASE_URL)
    const error = await repository.update(999, { locale: 'fr', value: 'x', label: 'x', iconKey: 'x', position: 0 }).catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(AdminStatsError)
    expect((error as AdminStatsError).reason).toBe('not-found')
  })

  it('lève une erreur "validation" sur 422 avec les messages de violation concaténés', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => jsonResponse(422, { violations: [{ propertyPath: 'value', message: 'This value should not be blank.' }] })),
    )

    const repository = new HttpAdminStatsRepository(API_BASE_URL)
    const error = await repository.create({ locale: 'fr', value: '', label: 'x', iconKey: 'x', position: 0 }).catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(AdminStatsError)
    expect((error as AdminStatsError).reason).toBe('validation')
    expect((error as AdminStatsError).message).toBe('This value should not be blank.')
  })

  it('lève une erreur "unknown" sur un statut inattendu', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(null, { status: 500 })))

    const repository = new HttpAdminStatsRepository(API_BASE_URL)
    const error = await repository.list('fr').catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(AdminStatsError)
    expect((error as AdminStatsError).reason).toBe('unknown')
  })
})
