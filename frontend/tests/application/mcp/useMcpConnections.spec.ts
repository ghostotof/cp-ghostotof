import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { MCP_CONNECTIONS_REPOSITORY, useMcpConnections } from '../../../src/application/mcp/useMcpConnections'
import { authState, markBaseAccessGranted, markSessionExpired } from '../../../src/application/auth/useAuth'
import type { McpConnectionsRepository } from '../../../src/domain/mcp/repositories/McpConnectionsRepository'
import type { McpConnectionSummary } from '../../../src/domain/mcp/entities/McpConnection'
import { McpConnectionNotFoundError } from '../../../src/domain/mcp/errors/McpConnectionNotFoundError'
import { McpConnectionsRequestFailedError } from '../../../src/domain/mcp/errors/McpConnectionsRequestFailedError'
import { SessionExpiredError } from '../../../src/domain/mcp/errors/SessionExpiredError'

enableAutoUnmount(afterEach)

function connection(id: string, state: McpConnectionSummary['state'] = 'active'): McpConnectionSummary {
  return {
    id,
    client: { id: 'claude-code', name: 'Claude Code' },
    grantedAt: '2026-10-28T09:00:00+01:00',
    lastUsedAt: null,
    expiresAt: '2026-11-27T09:00:00+01:00',
    state,
    revokedAt: 'revoked' === state ? '2026-10-28T11:00:00+01:00' : null,
    revocationCause: 'revoked' === state ? 'person' : null,
  }
}

const A = connection('a')
const B = connection('b')

function mountComposable(repository: McpConnectionsRepository) {
  let captured: ReturnType<typeof useMcpConnections> | undefined
  const Probe = defineComponent({
    setup() {
      captured = useMcpConnections()
      return () => h('div')
    },
  })
  mount(Probe, { global: { provide: { [MCP_CONNECTIONS_REPOSITORY as symbol]: repository } } })

  if (!captured) {
    throw new Error('useMcpConnections() did not run during mount')
  }

  return captured
}

describe('useMcpConnections', () => {
  beforeEach(() => {
    // Une session « connectée » à ramener à anonyme : markSessionExpired() en est le seul témoin observable.
    markSessionExpired()
    markBaseAccessGranted()
    expect(authState.tier).toBe('base')
  })

  it("lève une erreur explicite si le repository n'a pas été fourni", () => {
    const Probe = defineComponent({
      setup() {
        useMcpConnections()
        return () => h('div')
      },
    })

    expect(() => mount(Probe)).toThrow(/McpConnectionsRepository/)
  })

  it('est en chargement avant tout appel', () => {
    const connections = mountComposable({ list: vi.fn(async () => []), revoke: vi.fn() })

    expect(connections.status.value).toBe('loading')
    expect(connections.connections.value).toEqual([])
    expect(connections.revokingId.value).toBeNull()
    expect(connections.lastOutcome.value).toBeNull()
  })

  it('load() charge la liste puis passe à ready', async () => {
    const connections = mountComposable({ list: vi.fn(async () => [A, B]), revoke: vi.fn() })

    await connections.load()

    expect(connections.status.value).toBe('ready')
    expect(connections.connections.value).toEqual([A, B])
  })

  it("load() en échec passe à error et vide la liste", async () => {
    const list = vi.fn().mockResolvedValue([A]).mockRejectedValueOnce(new McpConnectionsRequestFailedError())
    const connections = mountComposable({ list, revoke: vi.fn() })

    await connections.load()

    expect(connections.status.value).toBe('error')
    expect(connections.connections.value).toEqual([])
  })

  it('load() sur une session expirée marque la session expirée', async () => {
    const connections = mountComposable({ list: vi.fn().mockRejectedValue(new SessionExpiredError()), revoke: vi.fn() })

    await connections.load()

    expect(connections.status.value).toBe('error')
    expect(authState.tier).toBe('anonymous')
  })

  it('revoke() révoque puis recharge la liste depuis le serveur, outcome revoked', async () => {
    const revoke = vi.fn(async () => undefined)
    const list = vi.fn().mockResolvedValueOnce([A, B]).mockResolvedValueOnce([A, connection('b', 'revoked')])
    const connections = mountComposable({ list, revoke })
    await connections.load()

    await connections.revoke('b')

    expect(revoke).toHaveBeenCalledWith('b')
    expect(list).toHaveBeenCalledTimes(2)
    expect(connections.connections.value[1]?.state).toBe('revoked')
    expect(connections.lastOutcome.value).toBe('revoked')
    expect(connections.revokingId.value).toBeNull()
    expect(connections.status.value).toBe('ready')
  })

  it('un 404 donne already-gone et recharge la liste', async () => {
    const revoke = vi.fn().mockRejectedValue(new McpConnectionNotFoundError())
    const list = vi.fn().mockResolvedValueOnce([A, B]).mockResolvedValueOnce([A])
    const connections = mountComposable({ list, revoke })
    await connections.load()

    await connections.revoke('b')

    expect(connections.lastOutcome.value).toBe('already-gone')
    expect(list).toHaveBeenCalledTimes(2)
    expect(connections.connections.value).toEqual([A])
    expect(connections.revokingId.value).toBeNull()
  })

  it('une erreur donne failed et laisse la liste inchangée, sans recharger', async () => {
    const revoke = vi.fn().mockRejectedValue(new McpConnectionsRequestFailedError())
    const list = vi.fn().mockResolvedValue([A, B])
    const connections = mountComposable({ list, revoke })
    await connections.load()

    await connections.revoke('b')

    expect(connections.lastOutcome.value).toBe('failed')
    expect(list).toHaveBeenCalledTimes(1)
    expect(connections.connections.value).toEqual([A, B])
    expect(connections.status.value).toBe('ready')
    expect(connections.revokingId.value).toBeNull()
  })

  it('une session expirée pendant la révocation marque la session expirée, outcome failed', async () => {
    const connections = mountComposable({
      list: vi.fn().mockResolvedValue([A]),
      revoke: vi.fn().mockRejectedValue(new SessionExpiredError()),
    })
    await connections.load()

    await connections.revoke('a')

    expect(authState.tier).toBe('anonymous')
    expect(connections.lastOutcome.value).toBe('failed')
    expect(connections.revokingId.value).toBeNull()
  })

  it('une erreur inattendue du dépôt donne failed aussi', async () => {
    const connections = mountComposable({
      list: vi.fn().mockResolvedValue([A]),
      revoke: vi.fn().mockRejectedValue(new Error('boom')),
    })
    await connections.load()

    await connections.revoke('a')

    expect(connections.lastOutcome.value).toBe('failed')
  })

  it('le rechargement qui suit une révocation en échec garde la révocation acquise (outcome revoked)', async () => {
    const list = vi.fn().mockResolvedValueOnce([A]).mockRejectedValueOnce(new McpConnectionsRequestFailedError())
    const connections = mountComposable({ list, revoke: vi.fn(async () => undefined) })
    await connections.load()

    await connections.revoke('a')

    // La révocation a eu lieu : le message le dit, la liste affichée est en erreur.
    expect(connections.lastOutcome.value).toBe('revoked')
    expect(connections.status.value).toBe('error')
  })

  it('deux revoke() de suite, sans attendre : une seule requête, revokingId posé', async () => {
    let release: () => void = () => undefined
    const revoke = vi.fn(() => new Promise<void>((resolve) => { release = resolve }))
    const connections = mountComposable({ list: vi.fn().mockResolvedValue([A, B]), revoke })
    await connections.load()

    const first = connections.revoke('a')
    const second = connections.revoke('b')

    expect(connections.revokingId.value).toBe('a')
    release()
    await Promise.all([first, second])

    expect(revoke).toHaveBeenCalledOnce()
    expect(revoke).toHaveBeenCalledWith('a')
    expect(connections.revokingId.value).toBeNull()
  })

  it("remet lastOutcome à null au début d'une nouvelle révocation", async () => {
    const revoke = vi.fn().mockRejectedValueOnce(new McpConnectionsRequestFailedError()).mockImplementationOnce(() => new Promise(() => undefined))
    const connections = mountComposable({ list: vi.fn().mockResolvedValue([A]), revoke })
    await connections.load()
    await connections.revoke('a')
    expect(connections.lastOutcome.value).toBe('failed')

    void connections.revoke('a')

    expect(connections.lastOutcome.value).toBeNull()
  })
})
