import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { HttpAdminExperienceTechnologyRepository } from '../../../../src/infrastructure/admin/technologies/HttpAdminExperienceTechnologyRepository'
import { AdminExperienceTechnologyError } from '../../../../src/domain/admin/technologies/errors/AdminExperienceTechnologyError'

const API_BASE_URL = 'https://api.example.test'

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

describe('HttpAdminExperienceTechnologyRepository', () => {
  beforeEach(() => {
    document.cookie = 'XSRF-TOKEN=csrf-token-value; path=/'
  })

  afterEach(() => {
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'
    vi.unstubAllGlobals()
  })

  it('list() appelle GET avec credentials include, sans header CSRF, et mappe la réponse (iconKey/relatedTechnologyName absents => null)', async () => {
    const fetchMock = vi.fn(async () =>
      jsonResponse(200, [{ id: 1, name: 'PHP', years: 13.5 }, { id: 2, name: 'Symfony', years: 9.5, iconKey: 'symfony', relatedTechnologyName: null }]),
    )
    vi.stubGlobal('fetch', fetchMock)

    const repository = new HttpAdminExperienceTechnologyRepository(API_BASE_URL)
    const technologies = await repository.list()

    // Match exact : garantit qu'aucun header (donc pas de X-XSRF-TOKEN) n'est ajouté sur une simple lecture.
    expect(fetchMock).toHaveBeenCalledWith(`${API_BASE_URL}/api/backoffice/experience/technologies`, {
      method: 'GET',
      credentials: 'include',
    })
    expect(technologies).toEqual([
      { id: 1, name: 'PHP', years: 13.5, iconKey: null, relatedTechnologyName: null },
      { id: 2, name: 'Symfony', years: 9.5, iconKey: 'symfony', relatedTechnologyName: null },
    ])
  })

  it('create() envoie POST avec le header CSRF et le corps JSON', async () => {
    const fetchMock = vi.fn(async () => jsonResponse(201, { id: 3, name: 'Vue', years: 3 }))
    vi.stubGlobal('fetch', fetchMock)

    const repository = new HttpAdminExperienceTechnologyRepository(API_BASE_URL)
    await repository.create({ name: 'Vue', years: 3, iconKey: null, relatedTechnologyName: null })

    expect(fetchMock).toHaveBeenCalledWith(
      `${API_BASE_URL}/api/backoffice/experience/technologies`,
      expect.objectContaining({
        method: 'POST',
        credentials: 'include',
        headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'csrf-token-value', 'Content-Type': 'application/json' }),
        body: JSON.stringify({ name: 'Vue', years: 3, iconKey: null, relatedTechnologyName: null }),
      }),
    )
  })

  it('update() envoie PUT vers /{id} avec le header CSRF', async () => {
    const fetchMock = vi.fn(async () => jsonResponse(200, { id: 3, name: 'Vue 3', years: 3 }))
    vi.stubGlobal('fetch', fetchMock)

    const repository = new HttpAdminExperienceTechnologyRepository(API_BASE_URL)
    await repository.update(3, { name: 'Vue 3', years: 3, iconKey: null, relatedTechnologyName: null })

    expect(fetchMock).toHaveBeenCalledWith(
      `${API_BASE_URL}/api/backoffice/experience/technologies/3`,
      expect.objectContaining({ method: 'PUT', headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'csrf-token-value' }) }),
    )
  })

  it('remove() envoie DELETE vers /{id} avec le header CSRF', async () => {
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }))
    vi.stubGlobal('fetch', fetchMock)

    const repository = new HttpAdminExperienceTechnologyRepository(API_BASE_URL)
    await repository.remove(3)

    expect(fetchMock).toHaveBeenCalledWith(
      `${API_BASE_URL}/api/backoffice/experience/technologies/3`,
      expect.objectContaining({ method: 'DELETE', headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'csrf-token-value' }) }),
    )
  })

  it('lève une erreur "duplicate" sur 409 avec le détail backend', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(409, { detail: 'Une technologie existe déjà avec le nom "PHP".' })))

    const repository = new HttpAdminExperienceTechnologyRepository(API_BASE_URL)
    const error = await repository.create({ name: 'PHP', years: 1, iconKey: null, relatedTechnologyName: null }).catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(AdminExperienceTechnologyError)
    expect((error as AdminExperienceTechnologyError).reason).toBe('duplicate')
    expect((error as AdminExperienceTechnologyError).message).toBe('Une technologie existe déjà avec le nom "PHP".')
  })

  it('lève une erreur "not-found" sur 404', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(404, { detail: 'Not Found' })))

    const repository = new HttpAdminExperienceTechnologyRepository(API_BASE_URL)
    const error = await repository.update(999, { name: 'X', years: 1, iconKey: null, relatedTechnologyName: null }).catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(AdminExperienceTechnologyError)
    expect((error as AdminExperienceTechnologyError).reason).toBe('not-found')
  })

  it('lève une erreur "validation" sur 422 avec les messages de violation concaténés', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () =>
        jsonResponse(422, {
          violations: [
            { propertyPath: 'name', message: 'This value should not be blank.' },
            { propertyPath: 'years', message: 'This value should be either positive or zero.' },
          ],
        }),
      ),
    )

    const repository = new HttpAdminExperienceTechnologyRepository(API_BASE_URL)
    const error = await repository.create({ name: '', years: -1, iconKey: null, relatedTechnologyName: null }).catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(AdminExperienceTechnologyError)
    expect((error as AdminExperienceTechnologyError).reason).toBe('validation')
    expect((error as AdminExperienceTechnologyError).message).toBe('This value should not be blank. This value should be either positive or zero.')
  })

  it('lève une erreur "unknown" sur un statut inattendu', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(null, { status: 500 })))

    const repository = new HttpAdminExperienceTechnologyRepository(API_BASE_URL)
    const error = await repository.list().catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(AdminExperienceTechnologyError)
    expect((error as AdminExperienceTechnologyError).reason).toBe('unknown')
  })
})
