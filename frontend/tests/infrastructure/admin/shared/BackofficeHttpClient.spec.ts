import { afterEach, describe, expect, it, vi } from 'vitest'
import { BackofficeHttpClient, setBackofficeSessionReaction } from '../../../../src/infrastructure/admin/shared/BackofficeHttpClient'
import type { BackofficeSendStamp, BackofficeSessionReaction } from '../../../../src/domain/admin/shared/BackofficeSessionReaction'
import { SessionEndedError } from '../../../../src/domain/auth/errors/SessionEndedError'
import { AccessForbiddenError } from '../../../../src/domain/auth/errors/AccessForbiddenError'

const API_BASE_URL = 'https://api.example.test'

function response(status: number): Response {
  // Un 204 n'a pas de corps (le constructeur le refuse).
  const body = 204 === status ? null : JSON.stringify({ code: status, message: 'x' })

  return new Response(body, { status, headers: { 'Content-Type': 'application/json' } })
}

/**
 * Une réaction du backoffice : `ended` est l'espion du verdict « session finie », `stamp` la timbre lu à
 * l'envoi, `refused` l'espion du refus réel (#748, R17).
 */
function reaction(
  ended: BackofficeSessionReaction['ended'] = () => true,
  stamp = 0,
  refused: BackofficeSessionReaction['refused'] = () => undefined,
): BackofficeSessionReaction {
  return { stampAtSend: () => stampOf(stamp), ended, refused }
}

/** Un timbre d'envoi : la génération de session `session`, le périmètre de refus étant sans objet ici. */
function stampOf(session: number, refusalScope = 0): BackofficeSendStamp {
  return { session, refusalScope }
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
 * jeton CSRF (#748, constat 15) : le client appelle la réaction puis **lève**
 * `SessionEndedError` (#748, R3), que les dépôts laissent traverser. Un 403 à une lecture
 * ou à une écriture qui portait son jeton est un refus réel (#748, R17) : le client appelle `refused` puis
 * **lève** `AccessForbiddenError`, que les composables taisent comme la session finie.
 */
describe('BackofficeHttpClient — fin de session', () => {
  afterEach(() => {
    setBackofficeSessionReaction(null)
    vi.unstubAllGlobals()
    clearCsrfCookie()
  })

  it.each([
    ['une lecture en 401', 'GET', 401, null],
    ['une écriture en 401', 'PUT', 401, null],
    ['une écriture en 403 partie sans cookie XSRF-TOKEN', 'DELETE', 403, null],
    ['une écriture en 403 partie avec un cookie XSRF-TOKEN vide', 'DELETE', 403, ''],
  ] as const)('%s lève SessionEndedError et appelle la réaction une fois', async (_label, method, status, cookie) => {
    const onSessionEnded = vi.fn(() => true)
    setBackofficeSessionReaction(reaction(onSessionEnded))
    const fetchMock = vi.fn(async () => response(status))
    vi.stubGlobal('fetch', fetchMock)
    if (null !== cookie) setCsrfCookie(cookie)
    const client = new BackofficeHttpClient(API_BASE_URL)

    const call = 'GET' === method ? client.get('/api/backoffice/users') : client.mutate(method, '/api/backoffice/users/1', { password: 'x' })

    await expect(call).rejects.toBeInstanceOf(SessionEndedError)
    expect(onSessionEnded).toHaveBeenCalledTimes(1)
  })

  it("cookie XSRF-TOKEN vide : l'écriture part sans en-tête", async () => {
    setBackofficeSessionReaction(reaction())
    const fetchMock = vi.fn(async () => response(403))
    vi.stubGlobal('fetch', fetchMock)
    setCsrfCookie('')

    await new BackofficeHttpClient(API_BASE_URL).mutate('DELETE', '/api/backoffice/users/1').catch(() => undefined)

    const init = fetchMock.mock.calls[0] as unknown as [string, RequestInit]
    expect(init[1].headers).not.toHaveProperty('X-XSRF-TOKEN')
  })

  it.each([200, 204, 404, 409, 422, 500])("un %i n'appelle aucune réaction et rend la réponse", async (status) => {
    const onSessionEnded = vi.fn(() => true)
    const onRefused = vi.fn()
    setBackofficeSessionReaction(reaction(onSessionEnded, 0, onRefused))
    vi.stubGlobal('fetch', vi.fn(async () => response(status)))
    setCsrfCookie('jeton')
    const client = new BackofficeHttpClient(API_BASE_URL)

    const read = await client.get('/api/backoffice/users')
    const write = await client.mutate('DELETE', '/api/backoffice/users/1')

    expect(onSessionEnded).not.toHaveBeenCalled()
    expect(onRefused).not.toHaveBeenCalled()
    expect(read.status).toBe(status)
    expect(write.status).toBe(status)
  })

  describe('refus réel (#748, R17)', () => {
    // Une lecture (GET), ou une écriture (PUT) partie avec son jeton.
    it.each(['GET', 'PUT'] as const)("403 sur %s : refused appelé une fois, AccessForbiddenError, jamais la session finie", async (method) => {
      const onSessionEnded = vi.fn(() => true)
      const onRefused = vi.fn()
      setBackofficeSessionReaction(reaction(onSessionEnded, 0, onRefused))
      vi.stubGlobal('fetch', vi.fn(async () => response(403)))
      // Le CSRF ne contrôle pas les lectures ; l'écriture, elle, porte son jeton : ce 403 est une autorisation refusée.
      setCsrfCookie('jeton')
      const client = new BackofficeHttpClient(API_BASE_URL)

      const call = 'GET' === method ? client.get('/api/backoffice/users') : client.mutate(method, '/api/backoffice/users/1', { a: 1 })

      await expect(call).rejects.toBeInstanceOf(AccessForbiddenError)
      expect(onRefused).toHaveBeenCalledTimes(1)
      expect(onSessionEnded).not.toHaveBeenCalled()
    })

    it('403 sans réaction enregistrée : AccessForbiddenError quand même', async () => {
      vi.stubGlobal('fetch', vi.fn(async () => response(403)))

      await expect(new BackofficeHttpClient(API_BASE_URL).get('/api/backoffice/users')).rejects.toBeInstanceOf(AccessForbiddenError)
    })

    it("refused reçoit le timbre lu à l'envoi, jamais un état lu à la réception", async () => {
      const calls: string[] = []
      setBackofficeSessionReaction({
        stampAtSend: () => {
          calls.push('stamp')

          return stampOf(7, 4)
        },
        ended: () => true,
        refused: (sentAt) => {
          calls.push(`refused:${sentAt.session}/${sentAt.refusalScope}`)
        },
      })
      vi.stubGlobal(
        'fetch',
        vi.fn(async () => {
          calls.push('fetch')

          return response(403)
        }),
      )

      await new BackofficeHttpClient(API_BASE_URL).get('/api/backoffice/users').catch(() => undefined)

      expect(calls).toEqual(['stamp', 'fetch', 'refused:7/4'])
    })

    it("un 403 d'écriture sans jeton reste une session finie, jamais un refus réel", async () => {
      const onRefused = vi.fn()
      setBackofficeSessionReaction(reaction(() => true, 0, onRefused))
      vi.stubGlobal('fetch', vi.fn(async () => response(403)))

      await expect(new BackofficeHttpClient(API_BASE_URL).mutate('PUT', '/api/backoffice/users/1', { a: 1 })).rejects.toBeInstanceOf(
        SessionEndedError,
      )
      expect(onRefused).not.toHaveBeenCalled()
    })
  })

  it("un 401 sans réaction enregistrée lève quand même SessionEndedError", async () => {
    vi.stubGlobal('fetch', vi.fn(async () => response(401)))

    await expect(new BackofficeHttpClient(API_BASE_URL).get('/api/backoffice/users')).rejects.toBeInstanceOf(SessionEndedError)
  })

  it('une panne réseau remonte telle quelle, sans réaction', async () => {
    const onSessionEnded = vi.fn(() => true)
    setBackofficeSessionReaction(reaction(onSessionEnded))
    const failure = new TypeError('Failed to fetch')
    vi.stubGlobal('fetch', vi.fn(async () => Promise.reject(failure)))

    await expect(new BackofficeHttpClient(API_BASE_URL).get('/api/backoffice/users')).rejects.toBe(failure)
    expect(onSessionEnded).not.toHaveBeenCalled()
  })

  describe('génération de session (#748, R14)', () => {
    it.each([
      ['une lecture', 'GET'],
      ['une écriture', 'PUT'],
    ] as const)(
      "%s en 401 partie avant le retour : la réponse est rendue, aucune SessionEndedError",
      async (_label, method) => {
        // Le verdict est jugé périmé par la réaction (`ended` faux) : la session a été rétablie depuis l'envoi.
        const ended = vi.fn(() => false)
        setBackofficeSessionReaction(reaction(ended, 3))
        vi.stubGlobal('fetch', vi.fn(async () => response(401)))
        const client = new BackofficeHttpClient(API_BASE_URL)

        const result = 'GET' === method ? await client.get('/api/backoffice/users') : await client.mutate(method, '/api/backoffice/users/1', {})

        expect(result.status).toBe(401)
        expect(ended).toHaveBeenCalledWith(stampOf(3))
      },
    )

    it('ended vrai : SessionEndedError', async () => {
      setBackofficeSessionReaction(reaction(() => true))
      vi.stubGlobal('fetch', vi.fn(async () => response(401)))

      await expect(new BackofficeHttpClient(API_BASE_URL).get('/api/backoffice/users')).rejects.toBeInstanceOf(SessionEndedError)
    })

    it("stampAtSend est lu avant l'envoi, et sa valeur rendue à ended", async () => {
      const calls: string[] = []
      setBackofficeSessionReaction({
        stampAtSend: () => {
          calls.push('stamp')

          return stampOf(7)
        },
        ended: (sentAt) => {
          calls.push(`ended:${sentAt.session}`)

          return true
        },
        refused: () => undefined,
      })
      vi.stubGlobal(
        'fetch',
        vi.fn(async () => {
          calls.push('fetch')

          return response(401)
        }),
      )

      await new BackofficeHttpClient(API_BASE_URL).get('/api/backoffice/users').catch(() => undefined)

      expect(calls).toEqual(['stamp', 'fetch', 'ended:7'])
    })
  })
})
