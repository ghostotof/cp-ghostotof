import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { HttpAdminQualityTraitRepository } from '../../../../src/infrastructure/admin/quality/HttpAdminQualityTraitRepository'
import { AdminQualityError } from '../../../../src/domain/admin/quality/errors/AdminQualityError'

const API_BASE_URL = 'https://api.example.test'

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

describe('HttpAdminQualityTraitRepository', () => {
  beforeEach(() => {
    document.cookie = 'XSRF-TOKEN=csrf-token-value; path=/'
  })

  afterEach(() => {
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'
    vi.unstubAllGlobals()
  })

  it('list() appelle GET filtré par locale, sans header CSRF, et mappe la réponse', async () => {
    const fetchMock = vi.fn(async () => jsonResponse(200, [{ id: 1, locale: 'fr', label: 'Testé', position: 0 }]))
    vi.stubGlobal('fetch', fetchMock)

    const repository = new HttpAdminQualityTraitRepository(API_BASE_URL)
    const traits = await repository.list('fr')

    expect(fetchMock).toHaveBeenCalledWith(`${API_BASE_URL}/api/backoffice/quality/traits?locale=fr`, {
      method: 'GET',
      credentials: 'include',
    })
    expect(traits).toEqual([{ id: 1, locale: 'fr', label: 'Testé', position: 0 }])
  })

  it('create() envoie POST avec le header CSRF et le corps JSON', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(201, { id: 2, locale: 'fr', label: 'Documenté', position: 1 })))

    const repository = new HttpAdminQualityTraitRepository(API_BASE_URL)
    await repository.create({ locale: 'fr', label: 'Documenté', position: 1 })

    expect(vi.mocked(fetch)).toHaveBeenCalledWith(
      `${API_BASE_URL}/api/backoffice/quality/traits`,
      expect.objectContaining({ method: 'POST', headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'csrf-token-value' }) }),
    )
  })

  it('update() envoie PUT vers /{id} avec le header CSRF', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(200, { id: 2, locale: 'fr', label: 'Documenté', position: 1 })))

    const repository = new HttpAdminQualityTraitRepository(API_BASE_URL)
    await repository.update(2, { locale: 'fr', label: 'Documenté', position: 1 })

    expect(vi.mocked(fetch)).toHaveBeenCalledWith(
      `${API_BASE_URL}/api/backoffice/quality/traits/2`,
      expect.objectContaining({ method: 'PUT', headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'csrf-token-value' }) }),
    )
  })

  it('remove() envoie DELETE vers /{id} avec le header CSRF', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(null, { status: 204 })))

    const repository = new HttpAdminQualityTraitRepository(API_BASE_URL)
    await repository.remove(2)

    expect(vi.mocked(fetch)).toHaveBeenCalledWith(
      `${API_BASE_URL}/api/backoffice/quality/traits/2`,
      expect.objectContaining({ method: 'DELETE', headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'csrf-token-value' }) }),
    )
  })

  it('lève une erreur "not-found" sur 404', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(404, { detail: 'Not Found' })))

    const repository = new HttpAdminQualityTraitRepository(API_BASE_URL)
    const error = await repository.update(999, { locale: 'fr', label: 'x', position: 0 }).catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(AdminQualityError)
    expect((error as AdminQualityError).reason).toBe('not-found')
  })

  it('lève une erreur "validation" sur 422 avec les messages de violation concaténés', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(422, { violations: [{ propertyPath: 'label', message: 'This value should not be blank.' }] })))

    const repository = new HttpAdminQualityTraitRepository(API_BASE_URL)
    const error = await repository.create({ locale: 'fr', label: '', position: 0 }).catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(AdminQualityError)
    expect((error as AdminQualityError).reason).toBe('validation')
    expect((error as AdminQualityError).message).toBe('This value should not be blank.')
  })

  it('lève une erreur "unknown" sur un statut inattendu', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(null, { status: 500 })))

    const repository = new HttpAdminQualityTraitRepository(API_BASE_URL)
    const error = await repository.list('fr').catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(AdminQualityError)
    expect((error as AdminQualityError).reason).toBe('unknown')
  })
})
