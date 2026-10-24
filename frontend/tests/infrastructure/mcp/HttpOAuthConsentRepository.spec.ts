import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { HttpOAuthConsentRepository } from '../../../src/infrastructure/mcp/HttpOAuthConsentRepository'
import type { ConsentParams } from '../../../src/domain/mcp/entities/ConsentRequest'
import { InvalidConsentRequestError } from '../../../src/domain/mcp/errors/InvalidConsentRequestError'
import { SessionExpiredError } from '../../../src/domain/mcp/errors/SessionExpiredError'
import { ConsentFailedError } from '../../../src/domain/mcp/errors/ConsentFailedError'
import { MalformedConsentDecisionError } from '../../../src/domain/mcp/errors/MalformedConsentDecisionError'
import { ReauthenticationRequiredError } from '../../../src/domain/mcp/errors/ReauthenticationRequiredError'

const PARAMS: ConsentParams = {
  responseType: 'code',
  clientId: 'claude-code',
  redirectUri: 'http://127.0.0.1:33418/callback',
  scope: 'nominative:read',
  state: 'a b&c',
  codeChallenge: 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
  codeChallengeMethod: 'S256',
  resource: 'https://site.example.test/api/mcp',
}

const SUMMARY = {
  client: { id: 'claude-code', name: 'Claude Code', registration: 'preregistered' },
  redirectHost: '127.0.0.1',
  scope: 'nominative:read',
  resource: 'https://site.example.test/api/mcp',
}

/** Le corps du 400 que le serveur rend quand il refuse la demande à la revalidation. */
const REJECTED = { type: '/errors/authorization-request-rejected', title: 'Bad Request', status: 400 }

/** Le corps du 401 que le serveur rend quand l'authentification est trop ancienne pour accorder. */
const REAUTHENTICATION_REQUIRED = { type: '/errors/reauthentication-required', title: 'reauthentication-required', status: 401 }

function jsonResponse(body: unknown, status = 200, contentType = 'application/json'): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': contentType } })
}

describe('HttpOAuthConsentRepository', () => {
  const fetchMock = vi.fn()
  const repository = new HttpOAuthConsentRepository('http://api.test')

  beforeEach(() => {
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    document.cookie = 'XSRF-TOKEN=tok%20en; path=/'
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'
  })

  describe('describe()', () => {
    it('GET avec le cookie de session et la requête reçue, en snake_case', async () => {
      fetchMock.mockResolvedValue(jsonResponse(SUMMARY))

      await repository.describe(PARAMS)

      expect(fetchMock).toHaveBeenCalledOnce()
      const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
      const parsed = new URL(url)
      expect(`${parsed.origin}${parsed.pathname}`).toBe('http://api.test/api/oauth/consent')
      expect(Object.fromEntries(parsed.searchParams)).toEqual({
        response_type: 'code',
        client_id: 'claude-code',
        redirect_uri: 'http://127.0.0.1:33418/callback',
        scope: 'nominative:read',
        state: 'a b&c',
        code_challenge: 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
        code_challenge_method: 'S256',
        resource: 'https://site.example.test/api/mcp',
      })
      expect(init.method).toBe('GET')
      expect(init.credentials).toBe('include')
    })

    it("omet de la requête un paramètre absent (state facultatif)", async () => {
      fetchMock.mockResolvedValue(jsonResponse(SUMMARY))

      await repository.describe({ ...PARAMS, state: null })

      const [url] = fetchMock.mock.calls[0] as [string]
      expect(new URL(url).searchParams.has('state')).toBe(false)
    })

    it('rend le résumé du serveur', async () => {
      fetchMock.mockResolvedValue(jsonResponse(SUMMARY))

      await expect(repository.describe(PARAMS)).resolves.toEqual(SUMMARY)
    })

    it('400 de type authorization-request-rejected : demande invalide ou altérée', async () => {
      fetchMock.mockResolvedValue(jsonResponse(REJECTED, 400, 'application/problem+json'))

      await expect(repository.describe(PARAMS)).rejects.toBeInstanceOf(InvalidConsentRequestError)
    })

    it.each([
      ['un autre type', jsonResponse({ type: '/errors/malformed-request-body' }, 400, 'application/problem+json')],
      ['sans type', jsonResponse({}, 400)],
      ['un type qui ne fait que contenir le slug', jsonResponse({ type: '/errors/authorization-request-rejected/x' }, 400)],
      ['un corps non JSON', new Response('<html>', { status: 400 })],
    ])('400 avec %s : échec générique, pas une demande invalide', async (_label, response) => {
      fetchMock.mockResolvedValue(response)

      await expect(repository.describe(PARAMS)).rejects.toBeInstanceOf(ConsentFailedError)
    })

    it.each([401, 403])('%i : session absente, expirée ou sans le palier de confiance', async (status) => {
      fetchMock.mockResolvedValue(jsonResponse({}, status))

      await expect(repository.describe(PARAMS)).rejects.toBeInstanceOf(SessionExpiredError)
    })

    it.each([404, 422, 429, 500, 503])('%i : échec générique', async (status) => {
      fetchMock.mockResolvedValue(jsonResponse({}, status))

      await expect(repository.describe(PARAMS)).rejects.toBeInstanceOf(ConsentFailedError)
    })

    it('réseau coupé : échec générique', async () => {
      fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))

      await expect(repository.describe(PARAMS)).rejects.toBeInstanceOf(ConsentFailedError)
    })

    it.each([
      ['corps non JSON', new Response('<html>', { status: 200 })],
      ['nom absent', jsonResponse({ ...SUMMARY, client: { id: 'x', registration: 'preregistered' } })],
      ["mode d'enregistrement inconnu", jsonResponse({ ...SUMMARY, client: { ...SUMMARY.client, registration: 'dynamic' } })],
      ['hôte de retour absent', jsonResponse({ ...SUMMARY, redirectHost: undefined })],
      // Le texte de la page décrit ce seul scope : un autre ouvrirait autre chose que ce qu'elle annonce.
      ['autre scope', jsonResponse({ ...SUMMARY, scope: 'nominative:write' })],
      ['scope élargi', jsonResponse({ ...SUMMARY, scope: 'nominative:read admin' })],
    ])('200 mal formé (%s) : échec générique, rien d’affiché à moitié', async (_label, response) => {
      fetchMock.mockResolvedValue(response)

      await expect(repository.describe(PARAMS)).rejects.toBeInstanceOf(ConsentFailedError)
    })
  })

  describe('decide()', () => {
    it.each([true, false])(
      'POST JSON en camelCase, cookie + X-XSRF-TOKEN, approved=%s',
      async (approved) => {
        fetchMock.mockResolvedValue(jsonResponse({ redirectUri: 'http://127.0.0.1:33418/callback?code=c' }))

        await repository.decide(PARAMS, approved)

        const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
        expect(url).toBe('http://api.test/api/oauth/consent')
        expect(init.method).toBe('POST')
        expect(init.credentials).toBe('include')
        expect(init.headers).toEqual({
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-XSRF-TOKEN': 'tok en',
        })
        expect(JSON.parse(init.body as string)).toEqual({ ...PARAMS, approved })
      },
    )

    it('renvoie un paramètre absent comme null, jamais une valeur calculée', async () => {
      fetchMock.mockResolvedValue(jsonResponse({ redirectUri: 'http://127.0.0.1:33418/callback?code=c' }))

      await repository.decide({ ...PARAMS, state: null }, true)

      const [, init] = fetchMock.mock.calls[0] as [string, RequestInit]
      expect(JSON.parse(init.body as string).state).toBeNull()
    })

    it('omet X-XSRF-TOKEN quand le cookie est absent', async () => {
      document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'
      fetchMock.mockResolvedValue(jsonResponse({ redirectUri: 'http://127.0.0.1:33418/callback?code=c' }))

      await repository.decide(PARAMS, true)

      const [, init] = fetchMock.mock.calls[0] as [string, RequestInit]
      expect(init.headers).not.toHaveProperty('X-XSRF-TOKEN')
    })

    it("rend l'URL de retour du serveur telle quelle", async () => {
      fetchMock.mockResolvedValue(
        jsonResponse({ redirectUri: 'http://127.0.0.1:33418/callback?error=access_denied&state=xyz' }),
      )

      await expect(repository.decide(PARAMS, false)).resolves.toBe(
        'http://127.0.0.1:33418/callback?error=access_denied&state=xyz',
      )
    })

    it('400 de type authorization-request-rejected : demande invalide ou altérée', async () => {
      fetchMock.mockResolvedValue(jsonResponse(REJECTED, 400, 'application/problem+json'))

      await expect(repository.decide(PARAMS, true)).rejects.toBeInstanceOf(InvalidConsentRequestError)
    })

    it('400 d’un autre type : échec générique', async () => {
      fetchMock.mockResolvedValue(jsonResponse({ type: '/errors/other' }, 400, 'application/problem+json'))

      await expect(repository.decide(PARAMS, true)).rejects.toBeInstanceOf(ConsentFailedError)
    })

    it('422 : corps de la décision mal formé, un défaut de la page', async () => {
      fetchMock.mockResolvedValue(jsonResponse({ violations: [] }, 422, 'application/problem+json'))

      await expect(repository.decide(PARAMS, true)).rejects.toBeInstanceOf(MalformedConsentDecisionError)
    })

    it.each([401, 403])('%i (CSRF refusé compris) : session expirée', async (status) => {
      fetchMock.mockResolvedValue(jsonResponse({}, status))

      await expect(repository.decide(PARAMS, true)).rejects.toBeInstanceOf(SessionExpiredError)
    })

    it('401 de type reauthentication-required : authentification trop ancienne pour accorder', async () => {
      fetchMock.mockResolvedValue(jsonResponse(REAUTHENTICATION_REQUIRED, 401, 'application/problem+json'))

      await expect(repository.decide(PARAMS, true)).rejects.toBeInstanceOf(ReauthenticationRequiredError)
    })

    it('403 portant ce type : reste une session expirée (seul le 401 le dit)', async () => {
      fetchMock.mockResolvedValue(jsonResponse(REAUTHENTICATION_REQUIRED, 403, 'application/problem+json'))

      await expect(repository.decide(PARAMS, true)).rejects.toBeInstanceOf(SessionExpiredError)
    })

    it('401 au corps illisible : session expirée', async () => {
      fetchMock.mockResolvedValue(new Response('<html>', { status: 401, headers: { 'Content-Type': 'text/html' } }))

      await expect(repository.decide(PARAMS, true)).rejects.toBeInstanceOf(SessionExpiredError)
    })

    it.each([429, 500])('%i : échec générique', async (status) => {
      fetchMock.mockResolvedValue(jsonResponse({}, status))

      await expect(repository.decide(PARAMS, true)).rejects.toBeInstanceOf(ConsentFailedError)
    })

    it('200 sans redirectUri : échec générique', async () => {
      fetchMock.mockResolvedValue(jsonResponse({}))

      await expect(repository.decide(PARAMS, true)).rejects.toBeInstanceOf(ConsentFailedError)
    })

    it('réseau coupé : échec générique', async () => {
      fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))

      await expect(repository.decide(PARAMS, true)).rejects.toBeInstanceOf(ConsentFailedError)
    })
  })
})
