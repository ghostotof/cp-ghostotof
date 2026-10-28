import { afterEach, describe, expect, it, vi } from 'vitest'
import { BackofficeHttpClient, setBackofficeUnauthorizedHandler } from '../../../../src/infrastructure/admin/shared/BackofficeHttpClient'

const API_BASE_URL = 'https://api.example.test'

function response(status: number): Response {
  // Un 204 n'a pas de corps (le constructeur le refuse).
  const body = 204 === status ? null : JSON.stringify({ code: status, message: 'x' })

  return new Response(body, { status, headers: { 'Content-Type': 'application/json' } })
}

/**
 * Un 401 du backoffice est une session finie (JWT expiré, ou ouverte avant un
 * changement de mot de passe, #650 R1b) ; un 403 est un refus d'autorisation
 * ou de CSRF, et garde le sens que chaque dépôt lui donne.
 */
describe('BackofficeHttpClient — 401', () => {
  afterEach(() => {
    setBackofficeUnauthorizedHandler(null)
    vi.unstubAllGlobals()
  })

  it('une lecture en 401 appelle la réaction et rend la réponse', async () => {
    const onUnauthorized = vi.fn()
    setBackofficeUnauthorizedHandler(onUnauthorized)
    vi.stubGlobal('fetch', vi.fn(async () => response(401)))

    const result = await new BackofficeHttpClient(API_BASE_URL).get('/api/backoffice/users')

    expect(onUnauthorized).toHaveBeenCalledTimes(1)
    expect(result.status).toBe(401)
  })

  it('une écriture en 401 appelle la réaction et rend la réponse', async () => {
    const onUnauthorized = vi.fn()
    setBackofficeUnauthorizedHandler(onUnauthorized)
    vi.stubGlobal('fetch', vi.fn(async () => response(401)))

    const result = await new BackofficeHttpClient(API_BASE_URL).mutate('PUT', '/api/backoffice/users/1/password', { password: 'x' })

    expect(onUnauthorized).toHaveBeenCalledTimes(1)
    expect(result.status).toBe(401)
  })

  it.each([200, 204, 403, 404, 409, 422, 500])('un %i n\'appelle pas la réaction', async (status) => {
    const onUnauthorized = vi.fn()
    setBackofficeUnauthorizedHandler(onUnauthorized)
    vi.stubGlobal('fetch', vi.fn(async () => response(status)))
    const client = new BackofficeHttpClient(API_BASE_URL)

    const read = await client.get('/api/backoffice/users')
    const write = await client.mutate('DELETE', '/api/backoffice/users/1')

    expect(onUnauthorized).not.toHaveBeenCalled()
    expect(read.status).toBe(status)
    expect(write.status).toBe(status)
  })

  it('un 401 sans réaction enregistrée rend la réponse, sans erreur', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => response(401)))

    const result = await new BackofficeHttpClient(API_BASE_URL).get('/api/backoffice/users')

    expect(result.status).toBe(401)
  })
})
