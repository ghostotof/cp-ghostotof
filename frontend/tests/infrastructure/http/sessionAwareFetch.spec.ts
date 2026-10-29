import { afterEach, describe, expect, it, vi } from 'vitest'
import { endsTheSession, sessionAwareFetch } from '../../../src/infrastructure/http/sessionAwareFetch'

const URL = 'https://api.example.test/api/anything'

function response(status: number): Response {
  // Un 204 n'a pas de corps (le constructeur le refuse).
  return new Response(204 === status ? null : JSON.stringify({ code: status }), { status, headers: { 'Content-Type': 'application/json' } })
}

function setCsrfCookie(value: string): void {
  document.cookie = `XSRF-TOKEN=${value}; path=/`
}

function clearCsrfCookie(): void {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'
}

/** Les `init` et en-têtes que `fetch` a reçus au premier appel. */
function sentInit(fetchMock: ReturnType<typeof vi.fn>): RequestInit & { headers?: Record<string, string> } {
  const init = fetchMock.mock.calls[0]?.[1] as RequestInit & { headers?: Record<string, string> }

  return init
}

/**
 * La règle unique du frontend (#748, constat 15) : un 401, ou un 403 à une écriture partie sans jeton
 * CSRF. Le CSRF ne contrôle que les écritures et passe avant le pare-feu : sans paire de cookies,
 * c'est lui qui répond, par un 403.
 */
describe('endsTheSession', () => {
  it.each([
    [401, false],
    [401, true],
    [403, true],
  ])('%i (envoyé sans jeton : %s) finit la session', (status, sentWithoutCsrfToken) => {
    expect(endsTheSession(status, sentWithoutCsrfToken)).toBe(true)
  })

  it.each([
    [403, false],
    [200, true],
    [404, true],
    [422, true],
    [500, true],
  ])('%i (envoyé sans jeton : %s) ne finit pas la session', (status, sentWithoutCsrfToken) => {
    expect(endsTheSession(status, sentWithoutCsrfToken)).toBe(false)
  })
})

describe('sessionAwareFetch', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
    clearCsrfCookie()
  })

  it("une lecture part avec les cookies et sans en-tête CSRF ; son 403 ne finit pas la session", async () => {
    // Un jeton est posé : une lecture ne le porte pas pour autant (le CSRF ne la contrôle pas).
    setCsrfCookie('abc')
    const fetchMock = vi.fn(async () => response(403))
    vi.stubGlobal('fetch', fetchMock)

    const judged = await sessionAwareFetch(URL, { method: 'GET' })

    expect(sentInit(fetchMock).credentials).toBe('include')
    expect(sentInit(fetchMock).headers?.['X-XSRF-TOKEN']).toBeUndefined()
    expect(judged.response.status).toBe(403)
    expect(judged.endedTheSession).toBe(false)
  })

  it("une écriture porte l'en-tête lu dans le cookie ; son 403 reste un refus", async () => {
    setCsrfCookie('abc')
    const fetchMock = vi.fn(async () => response(403))
    vi.stubGlobal('fetch', fetchMock)

    const judged = await sessionAwareFetch(URL, { method: 'POST' })

    expect(sentInit(fetchMock).credentials).toBe('include')
    expect(sentInit(fetchMock).headers?.['X-XSRF-TOKEN']).toBe('abc')
    expect(judged.endedTheSession).toBe(false)
  })

  it.each([
    ['sans cookie', null],
    ['cookie vide', ''],
  ])("écriture %s : aucun en-tête, et son 403 finit la session", async (_label, cookie) => {
    if (null !== cookie) {
      setCsrfCookie(cookie)
    }
    const fetchMock = vi.fn(async () => response(403))
    vi.stubGlobal('fetch', fetchMock)

    const judged = await sessionAwareFetch(URL, { method: 'DELETE' })

    expect(sentInit(fetchMock).headers?.['X-XSRF-TOKEN']).toBeUndefined()
    expect(judged.endedTheSession).toBe(true)
  })

  it("écriture avec XSRF-TOKEN=%E0 : part sans en-tête, et son 403 finit la session", async () => {
    // Un cookie indécodable est « absent » (F13) : avant, `decodeURIComponent` levait avant tout envoi.
    setCsrfCookie('%E0')
    const fetchMock = vi.fn(async () => response(403))
    vi.stubGlobal('fetch', fetchMock)

    const judged = await sessionAwareFetch(URL, { method: 'POST' })

    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(sentInit(fetchMock).headers?.['X-XSRF-TOKEN']).toBeUndefined()
    expect(judged.endedTheSession).toBe(true)
  })

  it('un 401 finit la session, en lecture comme en écriture', async () => {
    setCsrfCookie('abc')
    vi.stubGlobal('fetch', vi.fn(async () => response(401)))

    expect((await sessionAwareFetch(URL, { method: 'GET' })).endedTheSession).toBe(true)
    expect((await sessionAwareFetch(URL, { method: 'PUT' })).endedTheSession).toBe(true)
  })

  it.each([200, 204, 404, 409, 422, 500])('un %i ne finit pas la session', async (status) => {
    vi.stubGlobal('fetch', vi.fn(async () => response(status)))

    // Écriture sans jeton : le seul cas où un 403 compterait, mais pas un autre statut.
    const judged = await sessionAwareFetch(URL, { method: 'POST' })

    expect(judged.response.status).toBe(status)
    expect(judged.endedTheSession).toBe(false)
  })

  it('les en-têtes donnés sont gardés', async () => {
    setCsrfCookie('abc')
    const fetchMock = vi.fn(async () => response(200))
    vi.stubGlobal('fetch', fetchMock)

    await sessionAwareFetch(URL, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: '{}' })

    expect(sentInit(fetchMock).headers).toEqual({ 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': 'abc' })
    expect(sentInit(fetchMock).body).toBe('{}')
  })

  it("une panne réseau ou un abandon remonte tel quel", async () => {
    const network = new TypeError('Failed to fetch')
    vi.stubGlobal('fetch', vi.fn(async () => { throw network }))
    await expect(sessionAwareFetch(URL, { method: 'GET' })).rejects.toBe(network)

    const aborted = new DOMException('Aborted', 'AbortError')
    vi.stubGlobal('fetch', vi.fn(async () => { throw aborted }))
    await expect(sessionAwareFetch(URL, { method: 'POST' })).rejects.toBe(aborted)
  })
})
