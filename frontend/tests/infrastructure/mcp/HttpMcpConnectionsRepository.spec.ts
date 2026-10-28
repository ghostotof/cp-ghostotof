import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { HttpMcpConnectionsRepository } from '../../../src/infrastructure/mcp/HttpMcpConnectionsRepository'
import { McpConnectionNotFoundError } from '../../../src/domain/mcp/errors/McpConnectionNotFoundError'
import { McpConnectionsRequestFailedError } from '../../../src/domain/mcp/errors/McpConnectionsRequestFailedError'
import { SessionExpiredError } from '../../../src/domain/mcp/errors/SessionExpiredError'

const ID = '0199d6a0-7b1c-7c3e-9a4d-1f2e3d4c5b6a'

/** Une entrée telle que `OwnMcpConnectionsController` l'écrit (clés dans l'ordre de l'API). */
const ACTIVE = {
  id: ID,
  client: { id: 'claude-code', name: 'Claude Code' },
  grantedAt: '2026-10-28T09:00:00+01:00',
  lastUsedAt: '2026-10-28T10:30:00+01:00',
  expiresAt: '2026-11-27T09:00:00+01:00',
  state: 'active',
  revokedAt: null,
  revocationCause: null,
}

const REVOKED = {
  ...ACTIVE,
  id: '0199d6a0-7b1c-7c3e-9a4d-1f2e3d4c5b6b',
  lastUsedAt: null,
  state: 'revoked',
  revokedAt: '2026-10-28T11:00:00+01:00',
  revocationCause: 'person',
}

/** Le corps du 404 que le serveur rend pour une connexion inconnue ou d'un autre compte. */
const NOT_FOUND = { type: '/errors/mcp-connection-not-found', title: 'Not Found', status: 404 }

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

describe('HttpMcpConnectionsRepository', () => {
  const fetchMock = vi.fn()
  const repository = new HttpMcpConnectionsRepository('http://api.test')

  beforeEach(() => {
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    document.cookie = 'XSRF-TOKEN=tok%20en; path=/'
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'
  })

  describe('list()', () => {
    it('GET /api/me/mcp-connections avec le cookie de session', async () => {
      fetchMock.mockResolvedValue(jsonResponse({ connections: [], terminatedTruncated: false }))

      await repository.list()

      expect(fetchMock).toHaveBeenCalledOnce()
      const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
      expect(url).toBe('http://api.test/api/me/mcp-connections')
      expect(init.method).toBe('GET')
      expect(init.credentials).toBe('include')
    })

    it("rend les connexions dans l'ordre du serveur, avec leurs champs", async () => {
      fetchMock.mockResolvedValue(jsonResponse({ terminatedTruncated: false, connections: [ACTIVE, REVOKED] }))

      await expect(repository.list()).resolves.toEqual({ connections: [ACTIVE, REVOKED], terminatedTruncated: false })
    })

    it("rend une liste vide quand la personne n'a aucune connexion", async () => {
      fetchMock.mockResolvedValue(jsonResponse({ connections: [], terminatedTruncated: false }))

      await expect(repository.list()).resolves.toEqual({ connections: [], terminatedTruncated: false })
    })

    it("rend la troncature de l'historique que le serveur annonce", async () => {
      fetchMock.mockResolvedValue(jsonResponse({ connections: [ACTIVE], terminatedTruncated: true }))

      await expect(repository.list()).resolves.toEqual({ connections: [ACTIVE], terminatedTruncated: true })
    })

    it('garde telle quelle une cause de révocation inconnue du frontend', async () => {
      fetchMock.mockResolvedValue(jsonResponse({ terminatedTruncated: false, connections: [{ ...REVOKED, revocationCause: 'a-future-cause' }] }))

      const { connections: [connection] } = await repository.list()

      expect(connection?.revocationCause).toBe('a-future-cause')
    })

    it.each([401, 403])('un %i est une session expirée', async (status) => {
      fetchMock.mockResolvedValue(jsonResponse({ type: '/errors/anything' }, status))

      await expect(repository.list()).rejects.toBeInstanceOf(SessionExpiredError)
    })

    it.each([500, 429, 404])('un %i est un échec de la requête', async (status) => {
      fetchMock.mockResolvedValue(jsonResponse({}, status))

      await expect(repository.list()).rejects.toBeInstanceOf(McpConnectionsRequestFailedError)
    })

    it('un réseau coupé est un échec de la requête', async () => {
      fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))

      await expect(repository.list()).rejects.toBeInstanceOf(McpConnectionsRequestFailedError)
    })

    it("un corps qui n'est pas du JSON est un échec de la requête", async () => {
      fetchMock.mockResolvedValue(new Response('<html>proxy</html>', { status: 200 }))

      await expect(repository.list()).rejects.toBeInstanceOf(McpConnectionsRequestFailedError)
    })

    it.each([
      ['un corps sans la clé connections', { terminatedTruncated: false }],
      ['connections qui n\'est pas un tableau', { terminatedTruncated: false, connections: 'x' }],
      ['une entrée sans id', { terminatedTruncated: false, connections: [{ ...ACTIVE, id: undefined }] }],
      ['une entrée sans client', { terminatedTruncated: false, connections: [{ ...ACTIVE, client: undefined }] }],
      ['un client sans nom', { terminatedTruncated: false, connections: [{ ...ACTIVE, client: { id: 'x' } }] }],
      ['un grantedAt absent', { terminatedTruncated: false, connections: [{ ...ACTIVE, grantedAt: undefined }] }],
      ['un expiresAt qui n\'est pas une chaîne', { terminatedTruncated: false, connections: [{ ...ACTIVE, expiresAt: 12 }] }],
      ['un lastUsedAt ni chaîne ni null', { terminatedTruncated: false, connections: [{ ...ACTIVE, lastUsedAt: 12 }] }],
      ['un revokedAt ni chaîne ni null', { terminatedTruncated: false, connections: [{ ...ACTIVE, revokedAt: false }] }],
      ['une cause ni chaîne ni null', { terminatedTruncated: false, connections: [{ ...ACTIVE, revocationCause: 3 }] }],
      ['un état inconnu', { terminatedTruncated: false, connections: [{ ...ACTIVE, state: 'paused' }] }],
      ['un état absent', { terminatedTruncated: false, connections: [{ ...ACTIVE, state: undefined }] }],
      ['un terminatedTruncated absent', { connections: [ACTIVE] }],
      ['un terminatedTruncated qui n\'est pas un booléen (chaîne)', { connections: [ACTIVE], terminatedTruncated: 'true' }],
      ['un terminatedTruncated qui n\'est pas un booléen (nombre)', { connections: [ACTIVE], terminatedTruncated: 1 }],
      ['un terminatedTruncated nul', { connections: [ACTIVE], terminatedTruncated: null }],
    ])('%s est un échec de la requête', async (_label, body) => {
      fetchMock.mockResolvedValue(jsonResponse(body))

      await expect(repository.list()).rejects.toBeInstanceOf(McpConnectionsRequestFailedError)
    })
  })

  describe('revoke()', () => {
    it("DELETE /api/me/mcp-connections/{id} avec le cookie et le jeton CSRF", async () => {
      fetchMock.mockResolvedValue(new Response(null, { status: 204 }))

      await expect(repository.revoke(ID)).resolves.toBeUndefined()

      expect(fetchMock).toHaveBeenCalledOnce()
      const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
      expect(url).toBe(`http://api.test/api/me/mcp-connections/${ID}`)
      expect(init.method).toBe('DELETE')
      expect(init.credentials).toBe('include')
      expect((init.headers as Record<string, string>)['X-XSRF-TOKEN']).toBe('tok en')
    })

    it("encode l'identifiant dans le chemin", async () => {
      fetchMock.mockResolvedValue(new Response(null, { status: 204 }))

      await repository.revoke('a/b?c')

      const [url] = fetchMock.mock.calls[0] as [string]
      expect(url).toBe('http://api.test/api/me/mcp-connections/a%2Fb%3Fc')
    })

    it.each([401, 403])('un %i est une session expirée', async (status) => {
      fetchMock.mockResolvedValue(jsonResponse({}, status))

      await expect(repository.revoke(ID)).rejects.toBeInstanceOf(SessionExpiredError)
    })

    it('un 404 mcp-connection-not-found est « déjà terminée »', async () => {
      fetchMock.mockResolvedValue(jsonResponse(NOT_FOUND, 404))

      await expect(repository.revoke(ID)).rejects.toBeInstanceOf(McpConnectionNotFoundError)
    })

    it("un 404 d'un autre type (routeur, proxy) n'est pas « déjà terminée »", async () => {
      fetchMock.mockResolvedValue(jsonResponse({ type: '/errors/not-found', status: 404 }, 404))

      await expect(repository.revoke(ID)).rejects.toBeInstanceOf(McpConnectionsRequestFailedError)
    })

    it('un 404 au corps illisible est un échec de la requête', async () => {
      fetchMock.mockResolvedValue(new Response('<html>', { status: 404 }))

      await expect(repository.revoke(ID)).rejects.toBeInstanceOf(McpConnectionsRequestFailedError)
    })

    it('un 500 est un échec de la requête', async () => {
      fetchMock.mockResolvedValue(jsonResponse({}, 500))

      await expect(repository.revoke(ID)).rejects.toBeInstanceOf(McpConnectionsRequestFailedError)
    })

    it('un réseau coupé est un échec de la requête', async () => {
      fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))

      await expect(repository.revoke(ID)).rejects.toBeInstanceOf(McpConnectionsRequestFailedError)
    })
  })
})
