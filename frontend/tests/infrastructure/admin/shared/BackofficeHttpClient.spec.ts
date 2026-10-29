import { afterEach, describe, expect, it, vi } from 'vitest'
import { BackofficeHttpClient, setBackofficeUnauthorizedHandler } from '../../../../src/infrastructure/admin/shared/BackofficeHttpClient'

const API_BASE_URL = 'https://api.example.test'

function response(status: number): Response {
  // Un 204 n'a pas de corps (le constructeur le refuse).
  const body = 204 === status ? null : JSON.stringify({ code: status, message: 'x' })

  return new Response(body, { status, headers: { 'Content-Type': 'application/json' } })
}

function setCsrfCookie(value: string): void {
  document.cookie = `XSRF-TOKEN=${value}; path=/`
}

function clearCsrfCookie(): void {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'
}

/**
 * Un 401 du backoffice est une session finie (JWT expiré, ou ouverte avant un
 * changement de mot de passe, #650 R1b), comme un 403 à une écriture partie sans
 * jeton CSRF (#748, constat 15) ; un 403 à une lecture ou à une écriture qui
 * portait son jeton est un refus réel, et garde le sens que chaque dépôt lui donne.
 */
describe('BackofficeHttpClient — fin de session', () => {
  afterEach(() => {
    setBackofficeUnauthorizedHandler(null)
    vi.unstubAllGlobals()
    clearCsrfCookie()
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
    // Le jeton est posé : une écriture qui le porte et se fait refuser en 403 est un refus réel.
    setCsrfCookie('jeton')
    const client = new BackofficeHttpClient(API_BASE_URL)

    const read = await client.get('/api/backoffice/users')
    const write = await client.mutate('DELETE', '/api/backoffice/users/1')

    expect(onUnauthorized).not.toHaveBeenCalled()
    expect(read.status).toBe(status)
    expect(write.status).toBe(status)
    expect(client.endedTheSession(read)).toBe(false)
    expect(client.endedTheSession(write)).toBe(false)
  })

  it('une écriture en 403 partie sans jeton CSRF appelle la réaction', async () => {
    const onUnauthorized = vi.fn()
    setBackofficeUnauthorizedHandler(onUnauthorized)
    vi.stubGlobal('fetch', vi.fn(async () => response(403)))
    const client = new BackofficeHttpClient(API_BASE_URL)

    const result = await client.mutate('PUT', '/api/backoffice/users/1/password', { password: 'x' })

    expect(onUnauthorized).toHaveBeenCalledTimes(1)
    expect(result.status).toBe(403)
    expect(client.endedTheSession(result)).toBe(true)
  })

  it("cookie XSRF-TOKEN vide : l'écriture part sans en-tête, son 403 finit la session", async () => {
    const onUnauthorized = vi.fn()
    setBackofficeUnauthorizedHandler(onUnauthorized)
    const fetchMock = vi.fn(async () => response(403))
    vi.stubGlobal('fetch', fetchMock)
    setCsrfCookie('')
    const client = new BackofficeHttpClient(API_BASE_URL)

    const result = await client.mutate('DELETE', '/api/backoffice/users/1')

    const init = fetchMock.mock.calls[0] as unknown as [string, RequestInit]
    expect(init[1].headers).not.toHaveProperty('X-XSRF-TOKEN')
    expect(onUnauthorized).toHaveBeenCalledTimes(1)
    expect(client.endedTheSession(result)).toBe(true)
  })

  it("une lecture en 403 n'est jamais une fin de session", async () => {
    const onUnauthorized = vi.fn()
    setBackofficeUnauthorizedHandler(onUnauthorized)
    vi.stubGlobal('fetch', vi.fn(async () => response(403)))
    const client = new BackofficeHttpClient(API_BASE_URL)

    // Aucun cookie, mais le CSRF ne contrôle pas les lectures : ce 403 est une autorisation refusée.
    const result = await client.get('/api/backoffice/users')

    expect(onUnauthorized).not.toHaveBeenCalled()
    expect(client.endedTheSession(result)).toBe(false)
  })

  it('endedTheSession : vrai pour un 401, faux pour un 200', async () => {
    vi.stubGlobal('fetch', vi.fn(async (_url: string) => response(_url.endsWith('/expired') ? 401 : 200)))
    const client = new BackofficeHttpClient(API_BASE_URL)

    expect(client.endedTheSession(await client.get('/expired'))).toBe(true)
    expect(client.endedTheSession(await client.get('/alive'))).toBe(false)
  })

  it('un 401 sans réaction enregistrée rend la réponse, sans erreur', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => response(401)))

    const result = await new BackofficeHttpClient(API_BASE_URL).get('/api/backoffice/users')

    expect(result.status).toBe(401)
  })
})
