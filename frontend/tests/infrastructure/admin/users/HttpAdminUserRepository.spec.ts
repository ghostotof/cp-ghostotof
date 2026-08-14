import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { HttpAdminUserRepository } from '../../../../src/infrastructure/admin/users/HttpAdminUserRepository'
import { AdminUserError } from '../../../../src/domain/admin/users/errors/AdminUserError'

const API_BASE_URL = 'https://api.example.test'

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

describe('HttpAdminUserRepository', () => {
  beforeEach(() => {
    document.cookie = 'XSRF-TOKEN=csrf-token-value; path=/'
  })

  afterEach(() => {
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'
    vi.unstubAllGlobals()
  })

  it('list() appelle GET avec credentials include, sans header CSRF, et mappe la réponse', async () => {
    const fetchMock = vi.fn(async () =>
      jsonResponse(200, [
        { id: 1, username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] },
        { id: 2, username: 'jane', roles: ['ROLE_USER'] },
      ]),
    )
    vi.stubGlobal('fetch', fetchMock)

    const repository = new HttpAdminUserRepository(API_BASE_URL)
    const users = await repository.list()

    expect(fetchMock).toHaveBeenCalledWith(`${API_BASE_URL}/api/backoffice/users`, {
      method: 'GET',
      credentials: 'include',
    })
    expect(users).toEqual([
      { id: 1, username: 'super', roles: ['ROLE_SUPER', 'ROLE_USER'] },
      { id: 2, username: 'jane', roles: ['ROLE_USER'] },
    ])
  })

  it('remove() envoie DELETE vers /{id} avec le header CSRF', async () => {
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }))
    vi.stubGlobal('fetch', fetchMock)

    const repository = new HttpAdminUserRepository(API_BASE_URL)
    await repository.remove(2)

    expect(fetchMock).toHaveBeenCalledWith(
      `${API_BASE_URL}/api/backoffice/users/2`,
      expect.objectContaining({ method: 'DELETE', credentials: 'include', headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'csrf-token-value' }) }),
    )
  })

  it('changePassword() envoie PUT vers /{id}/password avec le header CSRF et le corps JSON, sans planter sur une réponse 204 sans corps', async () => {
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }))
    vi.stubGlobal('fetch', fetchMock)

    const repository = new HttpAdminUserRepository(API_BASE_URL)
    await expect(repository.changePassword(2, 'NewPassword123')).resolves.toBeUndefined()

    expect(fetchMock).toHaveBeenCalledWith(
      `${API_BASE_URL}/api/backoffice/users/2/password`,
      expect.objectContaining({
        method: 'PUT',
        credentials: 'include',
        headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'csrf-token-value', 'Content-Type': 'application/json' }),
        body: JSON.stringify({ password: 'NewPassword123' }),
      }),
    )
  })

  it('lève une erreur "cannot-delete-self" sur 409', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(409, { detail: 'L\'utilisateur "super" ne peut pas supprimer son propre compte.' })))

    const repository = new HttpAdminUserRepository(API_BASE_URL)
    const error = await repository.remove(1).catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(AdminUserError)
    expect((error as AdminUserError).reason).toBe('cannot-delete-self')
  })

  it('lève une erreur "not-found" sur 404', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(404, { detail: 'Not Found' })))

    const repository = new HttpAdminUserRepository(API_BASE_URL)
    const error = await repository.remove(999).catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(AdminUserError)
    expect((error as AdminUserError).reason).toBe('not-found')
  })

  it('lève une erreur "validation" sur 422 avec les messages de violation concaténés', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => jsonResponse(422, { violations: [{ propertyPath: 'password', message: 'This value is too short.' }] })),
    )

    const repository = new HttpAdminUserRepository(API_BASE_URL)
    const error = await repository.changePassword(1, 'short').catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(AdminUserError)
    expect((error as AdminUserError).reason).toBe('validation')
    expect((error as AdminUserError).message).toBe('This value is too short.')
  })

  it('lève une erreur "unknown" sur un statut inattendu', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(null, { status: 500 })))

    const repository = new HttpAdminUserRepository(API_BASE_URL)
    const error = await repository.list().catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(AdminUserError)
    expect((error as AdminUserError).reason).toBe('unknown')
  })
})
