import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { HttpAdminUserRepository } from '../../../../src/infrastructure/admin/users/HttpAdminUserRepository'
import { AdminUserError } from '../../../../src/domain/admin/users/errors/AdminUserError'

const API_BASE_URL = 'https://api.example.test'
const USER_ID = '019968a0-0000-7000-8000-000000000001'
const NEWCOMER_ID = '019968a0-0000-7000-8000-000000000002'
const SUPER_ADMIN_TARGET_ID = '019968a0-0000-7000-8000-000000000003'
const RESEND_TARGET_ID = '019968a0-0000-7000-8000-000000000004'
const INVITED_USER_ID = '019968a0-0000-7000-8000-000000000009'
const MISSING_USER_ID = '019968a0-0000-7000-8000-000000000999'

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

  it('list() appelle GET avec credentials include, sans header CSRF, et mappe email + status', async () => {
    const fetchMock = vi.fn(async () =>
      jsonResponse(200, [
        { id: USER_ID, username: 'super', email: null, roles: ['ROLE_SUPER', 'ROLE_USER'], status: 'active' },
        { id: NEWCOMER_ID, username: 'newcomer', email: 'newcomer@example.com', roles: ['ROLE_USER'], status: 'pending' },
      ]),
    )
    vi.stubGlobal('fetch', fetchMock)

    const users = await new HttpAdminUserRepository(API_BASE_URL).list()

    expect(fetchMock).toHaveBeenCalledWith(`${API_BASE_URL}/api/backoffice/users`, {
      method: 'GET',
      credentials: 'include',
    })
    expect(users).toEqual([
      { id: USER_ID, username: 'super', email: null, roles: ['ROLE_SUPER', 'ROLE_USER'], status: 'active' },
      { id: NEWCOMER_ID, username: 'newcomer', email: 'newcomer@example.com', roles: ['ROLE_USER'], status: 'pending' },
    ])
  })

  it('invite() envoie POST {email, locale} avec le header CSRF et retourne l\'utilisateur créé', async () => {
    const fetchMock = vi.fn(async () =>
      jsonResponse(201, { id: INVITED_USER_ID, username: 'jean.dupont', email: 'jean.dupont@example.com', roles: ['ROLE_USER'], status: 'pending' }),
    )
    vi.stubGlobal('fetch', fetchMock)

    const created = await new HttpAdminUserRepository(API_BASE_URL).invite('jean.dupont@example.com', 'fr')

    expect(fetchMock).toHaveBeenCalledWith(
      `${API_BASE_URL}/api/backoffice/users`,
      expect.objectContaining({
        method: 'POST',
        credentials: 'include',
        headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'csrf-token-value', 'Content-Type': 'application/json' }),
        body: JSON.stringify({ email: 'jean.dupont@example.com', locale: 'fr' }),
      }),
    )
    expect(created).toEqual({
      id: INVITED_USER_ID,
      username: 'jean.dupont',
      email: 'jean.dupont@example.com',
      roles: ['ROLE_USER'],
      status: 'pending',
    })
  })

  it('invite() lève "email-taken" sur 409', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(409, { detail: 'Un utilisateur existe déjà avec l\'adresse e-mail "x@y.fr".' })))

    const error = await new HttpAdminUserRepository(API_BASE_URL).invite('x@y.fr', 'fr').catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(AdminUserError)
    expect((error as AdminUserError).reason).toBe('email-taken')
  })

  it('invite() lève "email-invalid" sur 422 (motif distinct du mot de passe)', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(422, { violations: [{ propertyPath: 'email', message: 'This value is not a valid email address.' }] })))

    const error = await new HttpAdminUserRepository(API_BASE_URL).invite('not-an-email', 'fr').catch((caught: unknown) => caught)

    expect((error as AdminUserError).reason).toBe('email-invalid')
  })

  it('setSuperAdmin() envoie PUT /{id}/roles {superAdmin} avec le header CSRF', async () => {
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }))
    vi.stubGlobal('fetch', fetchMock)

    await new HttpAdminUserRepository(API_BASE_URL).setSuperAdmin(SUPER_ADMIN_TARGET_ID, true)

    expect(fetchMock).toHaveBeenCalledWith(
      `${API_BASE_URL}/api/backoffice/users/${SUPER_ADMIN_TARGET_ID}/roles`,
      expect.objectContaining({
        method: 'PUT',
        credentials: 'include',
        headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'csrf-token-value' }),
        body: JSON.stringify({ superAdmin: true }),
      }),
    )
  })

  it('setSuperAdmin() distingue "cannot-modify-own-roles" et "cannot-demote-last-super" sur 409 selon le `type` du problem+json', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => jsonResponse(409, { type: '/errors/cannot-modify-own-roles', detail: 'peu importe, localisé' })),
    )
    const ownRoles = await new HttpAdminUserRepository(API_BASE_URL).setSuperAdmin(USER_ID, false).catch((caught: unknown) => caught)
    expect((ownRoles as AdminUserError).reason).toBe('cannot-modify-own-roles')

    vi.stubGlobal(
      'fetch',
      vi.fn(async () => jsonResponse(409, { type: '/errors/cannot-demote-last-super', detail: 'peu importe, localisé' })),
    )
    const lastSuper = await new HttpAdminUserRepository(API_BASE_URL).setSuperAdmin(NEWCOMER_ID, false).catch((caught: unknown) => caught)
    expect((lastSuper as AdminUserError).reason).toBe('cannot-demote-last-super')
  })

  it('resendInvitation() envoie POST /{id}/invitation {locale} avec le header CSRF', async () => {
    const fetchMock = vi.fn(async () => new Response(null, { status: 202 }))
    vi.stubGlobal('fetch', fetchMock)

    await new HttpAdminUserRepository(API_BASE_URL).resendInvitation(RESEND_TARGET_ID, 'en')

    expect(fetchMock).toHaveBeenCalledWith(
      `${API_BASE_URL}/api/backoffice/users/${RESEND_TARGET_ID}/invitation`,
      expect.objectContaining({
        method: 'POST',
        credentials: 'include',
        headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'csrf-token-value' }),
        body: JSON.stringify({ locale: 'en' }),
      }),
    )
  })

  it('resendInvitation() lève "already-activated" sur 409', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(409, { detail: 'Le compte "jane" n\'est pas en attente d\'activation…' })))

    const error = await new HttpAdminUserRepository(API_BASE_URL).resendInvitation(RESEND_TARGET_ID, 'fr').catch((caught: unknown) => caught)

    expect((error as AdminUserError).reason).toBe('already-activated')
  })

  it('remove() envoie DELETE vers /{id} avec le header CSRF', async () => {
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }))
    vi.stubGlobal('fetch', fetchMock)

    await new HttpAdminUserRepository(API_BASE_URL).remove(NEWCOMER_ID)

    expect(fetchMock).toHaveBeenCalledWith(
      `${API_BASE_URL}/api/backoffice/users/${NEWCOMER_ID}`,
      expect.objectContaining({ method: 'DELETE', credentials: 'include', headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'csrf-token-value' }) }),
    )
  })

  it('changePassword() envoie PUT vers /{id}/password sans planter sur une réponse 204 sans corps', async () => {
    const fetchMock = vi.fn(async () => new Response(null, { status: 204 }))
    vi.stubGlobal('fetch', fetchMock)

    await expect(new HttpAdminUserRepository(API_BASE_URL).changePassword(NEWCOMER_ID, 'NewPassword123')).resolves.toBeUndefined()

    expect(fetchMock).toHaveBeenCalledWith(
      `${API_BASE_URL}/api/backoffice/users/${NEWCOMER_ID}/password`,
      expect.objectContaining({
        method: 'PUT',
        body: JSON.stringify({ password: 'NewPassword123' }),
      }),
    )
  })

  it('remove() lève "cannot-delete-self" sur 409', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(409, { detail: 'L\'utilisateur "super" ne peut pas supprimer son propre compte.' })))

    const error = await new HttpAdminUserRepository(API_BASE_URL).remove(USER_ID).catch((caught: unknown) => caught)

    expect((error as AdminUserError).reason).toBe('cannot-delete-self')
  })

  it('lève "not-found" sur 404', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(404, { detail: 'Not Found' })))

    const error = await new HttpAdminUserRepository(API_BASE_URL).remove(MISSING_USER_ID).catch((caught: unknown) => caught)

    expect((error as AdminUserError).reason).toBe('not-found')
  })

  it('lève "validation" sur 422 avec les messages de violation concaténés', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(422, { violations: [{ propertyPath: 'password', message: 'This value is too short.' }] })))

    const error = await new HttpAdminUserRepository(API_BASE_URL).changePassword(USER_ID, 'short').catch((caught: unknown) => caught)

    expect((error as AdminUserError).reason).toBe('validation')
    expect((error as AdminUserError).message).toBe('This value is too short.')
  })

  it('lève "unknown" sur un statut inattendu', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(null, { status: 500 })))

    const error = await new HttpAdminUserRepository(API_BASE_URL).list().catch((caught: unknown) => caught)

    expect((error as AdminUserError).reason).toBe('unknown')
  })
})
